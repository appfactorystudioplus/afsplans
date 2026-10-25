# Allocation (alloc_v2) — How It Works

**No feature is infinite.** Every countable thing a member can create — websites, email
accounts, forms, stores, processors, Direct Payments — has a per-tier ceiling, and that
ceiling lives in one file. This document explains the system so each app can adopt it the
same way.

---

## 1. The one-sentence version

A member has a **tier** (0–4). A registry file says what each tier gets for each resource. A
client asks the registry *"may I create one more?"* before creating, and tells it *"I created
one"* afterwards.

```ts
const allocV2 = createAllocV2(app);

allocV2.get(RegistryResourceIds.DOMAIN_EMAIL_ACCOUNTS, { domain: getSubdomainProperty(app, 'Domain') })
    .then((result) => {
        MyLogDebug(import.meta.env.VITE_LOG_LEVEL2, () => { console.log(result); });
    })
    .catch((error) => {
        console.error('Error fetching email accounts:', error);
    });
```

That is the shape every call takes: a **resource id**, an optional **scope**, a promise.

(That snippet is real code — `main.ts:565` — and it is also the only kind of allocation call
`www` currently makes: one that logs its result and does nothing with it. Read it as
illustration, not precedent; §12.2.)

---

## 2. The three pieces

| Piece | Where | What it is |
|---|---|---|
| **The registry** | `auth/public/checkpoint/checkpoint_registry_v3.json` | The limits. One entry per resource, five positional tiers |
| **The client** | `<app>/resources/js/checkpoint/alloc_v2.ts` | `CheckpointAllocV2` — talks to Parse directly |
| **The factory** | `<app>/resources/js/checkpointHelper/static/registry.ts` | `createAllocV2(app)` — wires the client with this app's identity |

Usage lives in **Parse** (`MemberRegistry`, one object per member). The registry file is the
**contract**; Parse holds the **counts**.

### 2.1 The registry entry

```jsonc
"domain_processors_count": {
  "label": "Payment Processors",
  "scope": "per_domain",        // member | per_domain | per_project
  "period": "lifetime",         // lifetime | monthly | daily
  "overage": "block",           // block | notify
  "unit": "processors",
  "tiers": [                    // INDEX IS THE CONTRACT — labels are cosmetic
    { "value": 0,  "enabled": false },   // 0 — not available on free
    { "value": 1,  "enabled": true  },   // 1
    { "value": 3,  "enabled": true  },   // 2
    { "value": 10, "enabled": true  },   // 3
    { "value": -1, "enabled": true  }    // 4 — unlimited
  ]
}
```

Four things to read out of that:

- **`tiers` is positional.** Index 0 is free, index 4 is the top tier. **Never resolve a tier
  by name** — the labels in `brain_memberships` (`Free/Pro/Business/Organization/Enterprise`)
  and in the registry (`free/starter/professional/business/enterprise`) differ, and either
  set can be edited for marketing reasons without anyone thinking of it as a schema change.
- **`value: -1` means unlimited.** Not zero, not null.
- **`enabled: false` means the feature does not exist at that tier** — which is different from
  a limit of zero, and is how "not available on free" is expressed without a second flag.
- **`overage`** decides what happens at the ceiling: `block` refuses, `notify` warns and
  allows.

### 2.2 The factory

```ts
export function createAllocV2(_app: ApplicationContextManager): CheckpointAllocV2 {
    return new CheckpointAllocV2({
        applicationId: import.meta.env.VITE_CHECKPOINT_APP_ID,
        serverURL:     import.meta.env.VITE_CHECKPOINT_SERVER_URL,
        parseObjectId: _app.window.member?.parse_alloc ?? '',
        tier:          Number(_app.window.member?.membership ?? 0),
        registry:      checkpointRegistryV3 as unknown as AllocV2Registry,
        getToken: () => { /* portal_auth_token from cookie / storage */ },
    });
}
```

- **`parse_alloc`** is the member's `MemberRegistry` objectId — the only member information
  the client needs.
- **`tier`** is `brain_memberships.membership`, passed straight through. `Number()` is not
  decoration: some payload shapes deliver it as a string, and a string index into a positional
  array yields `undefined`, which silently resolves **every limit to 0**.
- **`registry`** is passed in rather than fetched, so allocation has no second network
  dependency and no CORS consideration on the auth host.

---

## 3. Where the factory lives, per app

| App | Path |
|---|---|
| **www** | `resources/js/registry.ts` ⚠ |
| members, market, coordinator, notes, flow, dream_graph, pdf_editor | `resources/js/checkpointHelper/static/registry.ts` |

**`www` is the outlier** and its own comment says so. Anyone updating "the factory" across the
estate will find seven in one place and miss the eighth. Worth normalising; until then, worth
knowing.

The client itself (`alloc_v2.ts`) and the registry JSON live in each app's synced
`checkpoint/` directory. They are **copies of one source**, like `portal.d.ts` — so a registry
change means re-syncing every app, not editing one.

---

## 4. The API

```ts
await allocV2.getAll(forceRefresh?)         // every resource for this member
await allocV2.get(resourceId, scope?)       // one resource: limit, used, remaining
await allocV2.canUse(resourceId, n?, scope?)// may I create n more?
await allocV2.consume(resourceId, n?, scope?)// I created n
await allocV2.release(resourceId, n?, scope?)// I deleted n
await allocV2.getRemaining(resourceId, scope?)
await allocV2.getLimit(resourceId, scope?)
await allocV2.getUsage(resourceId, scope?)
await allocV2.isUnlimited(resourceId, scope?)
```

### 4.1 Scope

`scope` is required whenever the resource is not member-scoped:

| `scope` in the registry | Pass |
|---|---|
| `member` | nothing |
| `per_domain` | `{ domain: getSubdomainProperty(app, 'Domain') }` |
| `per_project` | `{ pm_project: '<name>' }` |

Getting this wrong does not error — it reads the wrong bucket. A `per_domain` resource queried
with no scope answers about a bucket that is not the one being written to.

### 4.2 Resource ids come from a constant

```ts
import { AllocV2Resources } from '@/checkpoint/resources';

allocV2.canUse(AllocV2Resources.DOMAIN_PROCESSORS_COUNT, 1, { domain })
```

Never a string literal. A typo'd literal resolves to an unknown resource rather than failing
loudly, and the two constant files (`checkpoint/resources.ts`, and `RegistryResourceIds` in
`registry.ts`) are the only names that should appear at a call site.

---

## 5. The pattern to follow

Three call sites per resource, and **all three are needed** — most bugs here are a missing
one:

```
CREATE button clicked   →  canUse()   →  proceed, or show the limit dialog
CREATE succeeded        →  consume()  →  the count goes up
DELETE succeeded        →  release()  →  the count comes back down
```

### 5.1 `canUse` before, `consume` after

`canUse` gates the **UI**; `consume` records the **fact**. They are not interchangeable:

- Consuming at the click means an abandoned form burns quota permanently.
- Only checking, never consuming, means the count never moves and the limit never bites.

`consume` runs **after the server confirms the create**, never before.

### 5.2 `release` on delete is the one that gets forgotten

A member at their limit who deletes something must be able to create again. Without `release`,
quota is a one-way ratchet and the member's only recovery is a support ticket.

**Only release what was consumed.** Deleting a row that predates allocation, or one whose
`consume` failed, would drive the count negative.

### 5.3 `consume` failures are non-fatal

```ts
allocV2.consume(AllocV2Resources.DOMAIN_DIRECT_PAYMENTS_COUNT, 1, { domain })
    .catch((err: unknown) => {
        const code = err instanceof AllocV2Error ? err.code : 'UNKNOWN';
        console.error(`AllocV2 consume error [${code}]:`, err);
        // Non-fatal: the record still saves even if the counter update fails.
    });
```

The thing was created. Failing the user's action because a counter did not increment would
lose real work over a bookkeeping error. Log it; let the reconciler catch drift.

`canUse`, by contrast, **may** block — that is its whole job.

### 5.4 Consume on create only, never on edit

```ts
if (!isEditing) {
    allocV2.consume(/* … */);
}
```

Saving an existing record is not a new one. The legacy `RegistryUpdate` was called
unconditionally on both paths and over-counted every edit — a member who edited one Direct
Payment ten times appeared to have eleven.

### 5.5 `monthly` and `daily` resources have no release

§5.2 says release is the call people forget. For **period** resources it is the call people
wrongly add.

A `lifetime` resource counts things you **hold** — forms, processors, stores. Deleting one gives
the allowance back.

A `monthly` or `daily` resource counts things that **happened** — submissions accepted, emails
sent. Deleting the record does not un-send the email, and purging last month's data does not
return this month's allowance. **The period reset is the release**, and the client already
implements it: `periodHasRolledOver()` (`alloc_v2.ts:562`) reads a counter carrying last
period's `periodStart` as zero.

Wiring a `release` on a monthly resource silently grants free quota to anyone who deletes their
own records.

### 5.6 Some resources are consumed by people who are not the member

The pattern in §5 assumes the consumer is signed in: `createAllocV2()` needs
`app.window.member`, which exists on a portal page and nowhere else.

Several resources are consumed on **public** endpoints, by anonymous visitors — form
submissions, guest checkout orders, event RSVPs, newsletter signups. There is no member object
in that browser, so there is no client to construct, and the request is untrusted anyway.

Those resources are enforced **server-side**, against a local authoritative counter, with a tier
from the Brain entitlement endpoint
([billing/09](billing/09-entitlement-integration.md) §9.3) — and alloc_v2 receives a *mirror* of
the number for display, never the decision. The same conclusion
[cloud-storage/00](cloud-storage/00-overview.md) **D4** reached for storage bytes.

[www/forms/15](www/forms/15-allocation.md) §15.5–§15.9 is the worked example: what the counter
table looks like, how the claim stays atomic, what an anonymous stranger sees at the ceiling,
and why the level-99 bypass behaves differently on that side (§15.10).

**Test for which side a resource belongs on:** *can the thing that consumes it be done by
someone without an account?* If yes, it is server-side, and no amount of client-side `canUse`
changes that.

---

## 6. The level-99 bypass

**`app.window.member.level === 99` bypasses every restriction and every allocation.**

That is the same superuser marker `main.ts:596` uses to reveal the Brain sidebar and
[site_users/12](www/site_users/12-builder-integration.md) §12.7 uses to gate advanced config.
One marker, one meaning, everywhere.

### 6.1 It lives in the factory, not at the call sites

```ts
/**
 * AFS_ALLOC_V2_TAG
 *
 * level === 99 is the platform superuser marker (main.ts:596). It bypasses
 * every allocation check.
 *
 * Passed into the client rather than checked at each call site, because there
 * are three call sites per resource across every app — and a bypass that has
 * to be remembered in each is a bypass that will be missed in one, giving a
 * staff account a limit dialog nobody can explain.
 *
 * EXACTLY 99, never >= 99. `level` defaults to 0 and has no documented ladder
 * above it, so it is a sentinel, not a rank.
 */
export function createAllocV2(_app: ApplicationContextManager): CheckpointAllocV2 {
    return new CheckpointAllocV2({
        // …existing config…
        bypass: Number(_app.window.member?.level ?? 0) === 99,
    });
}
```

Every call site is then unchanged and gets the bypass for free.

### 6.2 What each method does under bypass

| Method | Under bypass |
|---|---|
| `canUse()` | `{ available: true, unlimited: true, bypass: true }` — never blocks |
| `get()` / `getAll()` | Real usage, `limit: -1`, `enabled: true`, `bypass: true` |
| `getRemaining()` / `getLimit()` | `-1` |
| `isUnlimited()` | `true` |
| **`consume()`** | **No-op.** Resolves immediately, writes nothing |
| **`release()`** | **No-op** |

**`consume` is a no-op, not an increment.** A staff account creating fifty processors while
reproducing a bug would otherwise leave fifty in a real member's registry — and the member
finds out when they hit a limit they never used. Bypassing means *not counted*, not
*counted and ignored*.

`get()` still returns **real usage** so a staff member can see what a member has actually
consumed. Only the ceiling is lifted.

### 6.3 It is a UX bypass, not a permission

The bypass runs in the browser on a client-supplied `level`, exactly like the tier itself
(§7). It removes limit dialogs for staff; it grants nothing the API would not already accept
from anyone. Do not reach for it as an authorisation mechanism.

---

## 7. What this is not

**Allocation is advisory, not a security boundary.** `alloc_v2.ts` says so itself:

> *"`tier` is likewise supplied by the caller … With no server-side code in the path there is
> nowhere to derive it from a token, so limits enforced here are ADVISORY — a determined
> client can claim any tier."*

The tier comes from the client, and so does the level-99 bypass. Anything that must not be
forgeable needs its own server-side check — see [billing/09](billing/09-entitlement-integration.md)
§9.4 for the Brain-side entitlement endpoint, and §11 below for what closing this properly
looks like.

Treat allocation as **quota management and UX**: it stops honest members from exceeding a
plan, tells them why, and gives the upgrade prompt somewhere to live.

---

## 8. Adding a resource

1. **Add it to `checkpoint_registry_v3.json`** with five positional tiers. Index 0 is
   `enabled: false` for anything not on the free plan.
2. **Re-sync** the registry copy into every app's `checkpoint/` directory.
3. **Add the id** to `checkpoint/resources.ts` (and `RegistryResourceIds` where the app uses
   it).
4. **Wire the three call sites** (§5).
5. **Tag every edit** `AFS_ALLOC_V2_TAG` (§9).

Adding a registry entry without wiring the call sites gives a limit nothing enforces.
Wiring call sites without the registry entry gives a resource that resolves to zero and
blocks everything.

---

## 9. The `AFS_ALLOC_V2_TAG` marker

Every allocation change carries it, alongside whatever plan marker applies:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/16-allocation.md §16.3
 *
 * Preflight against domain_processors_count before opening the create modal.
 * per_domain scope, so the current subdomain's Domain property is passed.
 */
```

The tag exists because allocation call sites are **scattered by design** — three per resource,
in whatever controller owns that feature — and they need to be findable as a set. `grep -rn
"AFS_ALLOC_V2_TAG"` should return every place a quota is checked, consumed or released, in
every app.

Registered in [markers.md](markers.md).

---

## 10. Failure posture

| Situation | Behaviour |
|---|---|
| `parse_alloc` missing on the member | Client cannot resolve. **Fail closed for `block` resources**; do not silently allow |
| Parse unreachable | `block` resources deny; `notify` resources allow with a degraded flag |
| Tier out of range | Clamp to 0–4. A missing membership is **0**, never the top tier |
| Unknown resource id | Throw. A typo must not resolve to "unlimited" |
| `enabled: false` at this tier | Refuse, and say the feature is not on this plan — a different message from "you have reached your limit" |

The last row matters for the UI. *"Upgrade to use payment processors"* and *"You have used
3 of 3 processors"* are different problems and lead to different actions.

---

## 11. The Parse side — what is actually deployed

`auth/public/checkpoint/parse` is a **stock parse-server deployment**. It builds and it runs.
What it does *not* contain is any allocation logic — and because **nothing is in production**,
every gap below is cheap to close now and expensive to close later.

The fix is already designed in **[alloc-v2-parse-direct/](alloc-v2-parse-direct/)** — schema
[03](alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.2, permissions §3.3, identity §3.4,
functions §3.6, and the open storage decision
[05](alloc-v2-parse-direct/05-findings-and-execution-plan.md) §5.2. **This section is not a
second design.** It is the inventory of what is on the box today, measured against that plan,
plus two things that plan predates.

### 11.1 Read `config.ts`, not `cloud/schema.ts`

`cloud/schema.ts` exports `schemaDefinitions` and **nothing imports it**. `config.ts` line 1:

```ts
// import { schemaDefinitions } from './cloud/schema.js';
```

`config.ts` carries its own inline `schemaDef` const — a duplicate of the same `Registry`
definition — and that is what `config.schema.definitions` receives.

Already recorded as a blocker in
[05](alloc-v2-parse-direct/05-findings-and-execution-plan.md) §5.3, and repeated here because
it changes what every gap below means: **an edit to `cloud/schema.ts` deploys nothing** while
the diff looks entirely correct.

### 11.2 The gaps

| # | Gap | Consequence |
|---|---|---|
| **G1** | **Class-name mismatch.** The live schema declares **`Registry`**; the client uses **`MemberRegistry`** (`alloc_v2.ts:399`) | The class the client reads and writes has **no declaration at all**. The one class that was deliberately defined is not the one in use |
| **G2** | **Class-level permissions are fully public** — `find/count/get/update/create/delete: { '*': true }` | Anyone holding the application id, which ships in every client bundle, can read and rewrite **any** member's allocation. Set your own limits; clear someone else's usage |
| **G3** | **No allocation Cloud Functions.** `cloud/functions.ts` is 25 lines: `hello`, `helloAsyncFunction`, and a `beforeSave` on `TestObject` | No server-side tier derivation and no server-side ceiling. The client decides whether a consume is allowed, then performs it itself |
| **G4** | **Field shape does not match v3.** The schema declares `domains: Number`, `domain_processors: Object`, `sync_storage: Number`; the v3 shape is `member_resources`, `domains[]`, `pm_projects[]` with nested `{ key, used, periodStart }` | Shape A versus shape C in [01](alloc-v2-parse-direct/01-current-state-audit.md) §1.2. One of the two is dead |
| **G5** | **The atomic counter is probably inert** — see §11.3 | Every consume silently degrades to read-modify-write, which is the lost-update race allocation exists to prevent |

**G2 is the one to fix first**, and it is a configuration change rather than a build.

### 11.3 G5 in full — `strict` + `lockSchemas` versus the `ac_*` counters

[05](alloc-v2-parse-direct/05-findings-and-execution-plan.md) §5.2 posed a decision: nested
counters cannot be incremented atomically, because parse-server's `fieldNameIsValid` rejects
the dot path `member_resources.x.used` before the op reaches Mongo. Option **A** was to move
the counter to a flat top-level field.

**The client already did A.** `counterField()` (`alloc_v2.ts:623`) builds a flat name —
`ac_d_<domain>__<resource_id>` — `object.increment()` it (`:1352`), and writes the nested value
alongside as a human-readable projection.

The problem is on the server. `config.ts`:

```ts
schema: {
    definitions: schemaDef,
    lockSchemas: true,          // schema changes via API are locked
    strict: true,
    recreateModifiedFields: false,
    deleteExtraFields: false,
}
```

`ac_*` field names **embed the domain**, so they cannot be declared ahead of time — the set is
open and only known at runtime. Against a locked, strict schema, the first save of a new
`ac_*` column is rejected. The client anticipates exactly this (`isUnknownFieldError`, `:1478`,
which matches `code === 255`, `invalid field name` and **`schema is locked`**) and falls back to
saving the nested projection alone — with this comment:

> *"losing atomicity but never the operation itself"*

So the behaviour is deliberate and correctly degraded, but the degraded path is likely the
**only** path in the current configuration. The system reports as working while delivering
option **C**.

Three ways to make A real; this is a decision, not a detail:

| | What |
|---|---|
| **Relax the schema** | `lockSchemas: false` for `MemberRegistry`, so `ac_*` columns are created on demand. Simplest; gives up schema locking on the one class that most needs its CLPs locked — and those are independent settings, so this is less bad than it sounds |
| **Provision on demand** | The Cloud Function creates the `ac_*` column with the master key before incrementing. Keeps `lockSchemas`; adds a schema write to a hot path |
| **Counter class** | 05 §5.2's option A proper: `MemberRegistryCounter`, one row per (registry, resource, scope key), a declared top-level `used: Number`. No open field set at all |

**The third is the durable one.** The first is the one that makes today's client work tonight.

**Verify before choosing.** Whether a master-key save from a Cloud Function bypasses
`lockSchemas` decides between one and two, and it has not been tested. A single save against a
staging instance settles it.

### 11.4 The order that matters

**Lock the CLPs before or with the Cloud Functions, never after.** Functions added while the
class stays publicly writable are a front door beside an open window — and the window is the
one an attacker uses.

Nothing is in production, so there is no migration, no dual-write period and no compatibility
window. `MemberRegistry` can be defined correctly the first time. This is the cheapest it will
ever be.

---

## 12. Coverage gaps on the client side

Distinct from the Parse gaps: these are places the pattern (§5) is incompletely applied.

### 12.1 What is actually wired, estate-wide

Every use of `AllocV2Resources.*` and `RegistryResourceIds.*` outside the constant files, across
`www`, `members`, `market` and `apps`:

| Resource | `canUse` | `consume` | `release` | Where |
|---|---|---|---|---|
| `domain_email_accounts` | ✅ `email.ts:288`, `:384` | ✅ `email.ts:441` (guarded on `isNewAccount`) | ❌ — `email.ts:571` deletes with no release | `www` portal |
| `domain_email_sends_monthly` | ❌ | ❌ | n/a (monthly, `notify`) | read-only display, `email.ts:706` |
| `domain_direct_payments_count` | ✅ `directpayments.ts:145` | ✅ `:646` | ❌ | `www` portal |

**Three resources out of twenty-nine.** The other twenty-six — processors, stores, forms,
events, subscribers, notes, notebooks, PM projects, kanban boards, DreamGraph graphs, PDF
documents, VoIP numbers, storage, secondary users, `afs_connect` — have **no call site
anywhere**. They are limits the registry declares and nothing enforces.

### 12.2 `main.ts:565–591` is exploration code, not a call site

Three allocation calls fire on **every portal boot** — `get`, `get`, `canUse` — and each
one's only consumer is a debug log. Beneath them sits a **commented-out `consume`**.

```ts
allocV2.get(RegistryResourceIds.DOMAIN_EMAIL_ACCOUNTS, { domain: … })
    .then((result) => { MyLogDebug(…, () => { console.log(result); }); })
```

That is three Parse round-trips per portal load whose results are discarded, on the boot path.
It is also the snippet §1 uses to show the API's shape — which is fine as illustration and
misleading as precedent. **It should be deleted in the same pass that wires the first real
resource**, and this document should stop being the reason it looks sanctioned.

### 12.3 The rest

| # | Gap | Where |
|---|---|---|
| **G6** | **`release` is wired nowhere.** All three wired resources consume and never release; quota is a one-way ratchet estate-wide | §12.1 |
| **G7** | **No resource distinguishes `enabled: false` from "limit reached".** Direct Payments shows `"Not Authorized"` for both; email shows *"You have reached your email account quota"* for both — including for a tier where the feature does not exist | [billing/16](billing/16-allocation.md) §16.5 |
| **G8** | **`parse_alloc` may be empty.** The factory passes `?? ''` and the client throws *"no MemberRegistry object for the supplied parseObjectId"* | An empty objectId must **fail closed** for `block` resources, with its own message — a member whose registry was never created is not a member at their limit |
| **G9** | **No reconciler for any resource.** Counts drift and nothing detects it | [billing/16](billing/16-allocation.md) §16.7 covers payments; the rest have none |
| **G10** | **The `www` factory is at a different path** from the other seven apps (§3) | Any estate-wide factory change misses it |
| **G11** | **Twenty-six of twenty-nine registry resources are unwired** (§12.1) | A limit the product claims and does not have — which is worse than no limit, because it appears on a pricing page |
| **G13** | **The `ac_*` flat counter is the authority, and every hand-editing surface writes the nested projection instead.** `readUsage()` (`alloc_v2.ts:1298`) checks `ac_*` first and only falls back to `entry.used` | Correcting a counter by editing the v3 structure — the Brain admin JSON editor, a migration, a script — changes nothing the member experiences. [members_brain/03](members_brain/03-the-data-model.md) §3.5. **Write both, always** |
| **G12** | **`canUse` failures fail open.** `email.ts:288` and `:384` wrap the call in `try`/`catch` and fall through to the create path on error | Both resources are `overage: block`. The correct posture is §10 and [billing/16](billing/16-allocation.md) §16.6: **check failures deny** |

**G8 and G12 are the same shape and the subtle one.** A thrown check is caught, logged, and
the create proceeds — failing *open* on the resource class specified to `block`. It is not a
missing feature; it is an inverted one, and it will not show up in any audit that counts call
sites.

---

## 13. Auditing the rest

`grep -rn "AFS_ALLOC_V2_TAG"` should eventually return three call sites per wired resource.
Today it returns nothing at all — the tag is new with this plan, and §12.1 is what a manual
sweep found in its place.

The audit is mechanical, and §12.1 is its first pass. For each of the twenty-nine ids in
`checkpoint_registry_v3.json`: find its `canUse`, `consume` and `release`, or record that the
resource is unwired. The output is a table, and the table is the backlog.

Two plans have already done their slice and are the templates for the rest:

| Plan | Resources | Shape |
|---|---|---|
| [billing/16](billing/16-allocation.md) | `domain_processors_count`, `domain_direct_payments_count` | The standard three call sites (§5) |
| [www/forms/15](www/forms/15-allocation.md) | `domain_forms_count`, `domain_forms_submissions_monthly` | **Both shapes in one module** — client-side for the admin count, server-side for the public submission meter (§5.6). Also decides `forms_items_*` should be deleted rather than wired |
| [members_brain/](members_brain/) | all 29, read/write | The **operator** side rather than the consumer side: a generated console over the Parse object, per-member ceiling overrides, and the audit trail for editing someone else's quota |

Two things to decide as it is filled in, because they are product questions rather than
engineering ones:

- **Which unwired resources should exist at all.** Twenty-six unenforced limits is also a
  signal that some were speculative. Deleting a registry entry is cheaper than wiring three
  call sites for a limit nobody intended to sell.
- **Which app owns each one.** `notes_*`, `pm_*`, `dreamgraph_*` and `pdf_editor_*` are not
  `www`'s to wire, and each app's adoption is its own piece of work with its own factory
  (§3).
