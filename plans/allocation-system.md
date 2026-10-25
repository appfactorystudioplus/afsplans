# The Allocation System — how quota works in this estate

**AFS_ALLOC_V2_TAG**

A reference for the system as it stands. Not a plan and not a changelog: it describes what
exists, how the pieces fit, and what a feature has to do to participate. Where a design
choice is not obvious, the reason is given — because the reason is the part that stops
someone undoing it.

Companion documents: `allocation.md` (the original design and its known gaps),
`TODO-member-resources.md` (per-resource wiring status),
`membership_change/` (what happens to held items when the plan changes),
`00_ISSUES.md` (environment and infrastructure faults).

---

## 1. What the system is for

Every plan tier in the estate promises a member some quantity of things: 2 email accounts
per domain on Free, 50 on Professional; 512 MB of storage on Free, 50 GB on Business. The
allocation system is the single answer to three questions, asked from anywhere in the
estate:

1. **May this member create one more of these?** — the *forward edge*.
2. **How many do they hold right now, and out of how many?** — the *reading*.
3. **They deleted one; give the slot back.** — the *release*.

There is a fourth question the system is being built toward but does not yet answer for
most resources: *they downgraded and now hold more than the new plan allows — which ones
lose?* That is the *backward edge*, and it is the subject of `membership_change/`. The
storage model described here was chosen largely to make that question answerable.

---

## 2. The three moving parts

```
                        checkpoint_registry_v4.json
                     (served by auth, one file, no copies)
                                     │
                    "what is a member of tier N allowed?"
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        │                            │                            │
   alloc_v2.ts                 PHP gateways                Brain admin UI
  (browser / Node)         (server-to-server)            (operator edits)
        │                            │                            │
        └────────────────────────────┼────────────────────────────┘
                                     │
                    "how many does this member hold?"
                                     │
                              Parse Server
                              MemberResource
```

- **The registry** is the *policy*. It is a static JSON file. It knows nothing about any
  member.
- **Parse** is the *ledger*. It knows what each member holds. It knows nothing about limits.
- **The clients** (`alloc_v2.ts` and the PHP gateways) join the two: read the policy, read
  the ledger, decide.

The separation is deliberate and load-bearing. A tier change is a deploy of one file and
takes effect everywhere at once, with no migration and no counter rewriting, because no
member's stored data mentions a limit.

---

## 3. Parse Server, and why the storage looks the way it does

### 3.1 What Parse is here

Parse Server is a hosted object store with a REST and JavaScript API, backed by MongoDB. In
this estate it holds one thing: allocation usage. It is reachable at
`PARSE_SERVER_URL` (`https://dashboard15-registry.appfactory.studio/parse`), addressed by an
**Application ID** from the browser and by a **Master Key** from servers.

The Application ID is not a secret — Parse Application IDs are designed to ship in client
bundles, and `alloc_v2.ts` carries one. The Master Key is the secret; it never leaves a
server, and it is what lets the PHP side bypass class-level permissions.

### 3.2 The one Parse fact the whole design turns on

`Parse.Object#increment()` compiles to a MongoDB `$inc`: the read and the write happen in a
single server-side operation, so two concurrent increments both land. It is the only atomic
primitive available to a client without writing Cloud Code.

**It works only on top-level fields.** parse-server validates field names against
`/^[A-Za-z][A-Za-z0-9_]*$/`, so a dotted path like `member_resources.<id>.used` is rejected
outright — not merely unsupported, refused.

Everything below follows from that sentence. If usage is to be incremented atomically, and
increment only reaches top-level fields, then **each counter must be its own object**, with
`used` at the top level. That is the entire justification for the row model.

### 3.3 The one class

> **There used to be two.** A `MemberRegistry` anchor object held one per member, and
> `brain_memberships.parse_alloc` stored its `objectId`. The anchor carried exactly one
> meaningful field — `member_reference_num` — which every row below already carries.
> Nothing joined through it: `registry_id` on a row was a plain string, not a Parse
> pointer, so Parse enforced no relationship and a member with no anchor read perfectly
> well. It cost a round trip on every cold read, a MySQL column to keep in step, an
> orphaned object on every failed signup, and one real failure mode — a `parse_alloc`
> naming a deleted anchor made every quota read throw against rows that were intact.
> The class, the column and the `registry_id` field are gone. Identity is the member
> reference, end to end.

#### `MemberResource` — the ledger row

**One row per `(member, resource_id, scope_type, scope_parent, scope_key)`.**

| Field | Meaning |
|---|---|
| `member_reference_num` | Owning member, and the only member identity there is. Every query filters on this. |
| `resource_id` | Registry key, e.g. `domain_email_accounts`. |
| `scope_type` | `member` \| `per_domain` \| `per_store` \| `per_project` \| `per_graph` \| `per_document` \| `per_design_project` |
| `scope_key` | The instance name. Empty string for member scope. |
| `scope_parent` | Parent instance for a nested scope. Empty otherwise. |
| `ledger_key` | `member` \| `resource` \| `scope_type` \| `scope_parent` \| `scope_key`, joined by pipes. The uniqueness key. |
| `used` | Amount active and counting toward the allowance. **The atomic increment target.** |
| `retired` | Amount held in retired bands — exists, does not count, purges later. |
| `state` | `active` \| `retired` \| `purging` \| `suspended` |
| `retired_at` | When the bands behind this row were retired. |
| `purge_after` | Earliest deletion deadline. Frozen, not advanced, while suspended. |
| `frozen_remaining_s` | Grace seconds preserved when suspended mid-grace. |
| `period_start` | Period resources only. |
| `limit_override` | Per-row limit override; null means the tier allowance applies. |

Indexes that matter: `ledger_key` (uniqueness), `member_reference_num`,
`member_reference_num + resource_id`, and `state + purge_after` for the retention sweep.

### 3.4 What one row per counter buys

- **`used` is top-level**, so `increment()` is a real `$inc` and concurrent consumes cannot
  lose each other.
- **One number, not two.** There is no mirror field and no projection, so there is no
  possibility of the value an admin edits differing from the value enforcement reads.
- **Every counter has its own `objectId`, `createdAt` and `updatedAt`.** Concurrency can be
  checked per counter rather than per member, and provenance is per counter.
- **Retention becomes an indexed query.** "Every row past its purge deadline" is
  `state + purge_after`, not a scan that walks every container of every member.
- **Instance existence is derived.** There is no separate list of a member's domains to keep
  in step with their counters; the distinct `scope_key` values *are* the list.
- **A member is derived too.** There is no per-member object to create, point at, or leave
  orphaned — a member simply *is* the set of rows carrying their reference.

---

## 4. `ledger_key` — the contract between four implementations

```
member_reference_num | resource_id | scope_type | scope_parent | scope_key
```

Member scope leaves the last two empty, so it ends in `||`:

```
m1xJppRmdtH2Y|storage_files_size_kb|member||
m1xJppRmdtH2Y|domain_email_accounts|per_domain||example.com
m1xJppRmdtH2Y|domain_ecommerce_items_per_store|per_store|example.com|main-store
```

Four independent implementations build this string:

| Where | Function |
|---|---|
| `resources/js/checkpoint/alloc_v2.ts` (×9 apps) | `ledgerKey()` |
| `auth/.../AllocationV2Controller.php` | `ledgerKey()` |
| `auth/.../MemberInitController.php` | `ledgerKey()` |
| `auth/.../AllocationGateway.php`, `coordinator/.../ParseAllocationGateway.php` | `ledgerKey()` |
| `www/.../AllocReconcilePayments.php` | inline |

They must produce **byte-identical** strings. A single character of divergence does not
throw and does not log — it silently creates a *second* row for the same counter, and
whichever writer ran first wins every subsequent read. The member sees a quota that is
wrong by exactly the amount the other implementation has been recording.

**Always find-or-create on `ledger_key`. Never blind-create.** Two concurrent first-writes
would otherwise split one counter in half.

---

## 5. The registry — `checkpoint_registry_v4.json`

Lives at `w/auth/public/checkpoint/checkpoint_registry_v4.json` and is served as a static
file. **Nothing bundles a copy.** Every consumer — all eight JS apps and coordinator's PHP
gateway — fetches this one URL at runtime and memoises it for the life of the process. That
is what makes a tier change a one-file deploy rather than eight rebuilds, and it is why a
bundled copy is not an optimisation but a way for two environments to disagree.

Current shape: **34 resources**, across 7 scopes.

| Dimension | Distribution |
|---|---|
| scope | member 14 · per_domain 15 · per_store 1 · per_project 1 · per_graph 1 · per_document 1 · per_design_project 1 |
| period | lifetime 30 · monthly 4 |
| retention | banded 29 · none 4 · binary 1 |
| tier_mode | absolute 33 · cumulative 1 |
| unit_kind | discrete 33 · continuous 1 |
| overage | block 33 · notify 1 |

### 5.1 A resource entry

```json
"domain_email_accounts": {
  "label": "Email Accounts per Domain",
  "description": "Number of individual mailboxes that can be provisioned inside a single domain.",
  "group": "domains",
  "category": "email",
  "feature_flag": { "enabled": true, "pass": [] },
  "scope": "per_domain",
  "unit": "accounts",
  "period": "lifetime",
  "overage": "block",
  "warn_at_pct": 90,
  "tiers": [
    { "value": 2,   "enabled": true },
    { "value": 10,  "enabled": true },
    { "value": 50,  "enabled": true },
    { "value": 250, "enabled": true },
    { "value": -1,  "enabled": true }
  ],
  "tier_mode": "absolute",
  "unit_kind": "discrete",
  "retention": "banded",
  "grace_days": 30,
  "inventory": { "app": "www", "table": "users_email" }
}
```

### 5.2 The conventions that are easy to get wrong

**Tiers are positional.** `tiers[n]` is the entry for tier index `n`, where `n` is
`brain_memberships.membership` clamped to 0–4. **Never resolve a tier by name.** The label
sets differ across the estate — `Free/Pro/Business/Organization/Enterprise` in one place,
`free/starter/professional/business/enterprise` in another — and labels carry no contract.
A string tier index also silently indexes the array as `undefined`, resolving every limit to
0, which is why clients coerce with `Number()`.

**`-1` means unlimited**, everywhere, and is the `unlimited_sentinel`.

**`tier_mode`** says how to read `tiers[]`:
- `absolute` — `tiers[n].value` *is* the total allowance at tier n; it replaces the one below.
- `cumulative` — the allowance at tier n is the **sum** of `tiers[0..n].value`; each tier
  adds a band. Only `storage_files_size_kb` uses this.

This distinction only bites when computing *band* capacities for retention: in cumulative
mode the tier values are already the per-band increments, in absolute mode the band
capacities are the successive **differences**.

**`period`** — `lifetime` never resets; `monthly` resets at the start of each calendar
month; `daily` at midnight UTC. A period counter carries `period_start` on its row and is
read as 0 once that period has rolled over. **The period is not part of the ledger key** —
one row serves a monthly counter for its whole life, rather than accruing a row per month.

**`overage`** — `block` rejects the operation at the limit; `notify` permits it and flags a
warning in the response.

**`feature_flag`** is evaluated **before** any tier or allocation check, and is a different
thing from a limit:
- `enabled: false` is a global kill switch. Nobody may use it, at any tier.
  **Never surface it as an upgrade prompt** — a higher plan does not unlock it.
- `pass: []` means everyone. A non-empty array is an allowlist of `member.level` values.
  A level denial is also not an upgrade prompt.
- `member.level === 99` is the application itself: it short-circuits every check, including
  `enabled: false`, and is never written into a `pass` array.

**`retention`** says what a downgrade does to items already held:
- `none` — nothing is retired. Correct for every period resource: a send that already
  happened cannot be un-sent, and the period reset *is* the release.
- `binary` — the resource is simply unavailable where `enabled: false`. Access closes,
  nothing is deleted.
- `banded` — items belong to bands 0–4; plan P owns bands 0..P. On downgrade, bands above P
  become read-only for `grace_days`, then purge.

**`unit_kind`** matters only for banded retention: `discrete` items cost exactly 1 and fit
any band with a free slot; a `continuous` item (a file) has a size and can be too large for
any single band even when total free space would hold it.

**`account_status`** is a second, orthogonal axis. The tier says which bands are *owned*;
`brain_memberships.account_status` says whether owned bands may be *used*. Status 2
(suspended) freezes every band read-only and **preserves** any running purge clock rather
than advancing it — an administrative hold must never delete data while the member is locked
out and unable to act on it.

**`scope_graph`** declares parent–child nesting. Retiring an instance of a parent scope
cascades into every resource scoped beneath it — `per_store` sits under `per_domain`, which
sits under `member`. `member_domains_count` has the largest blast radius in the registry.

---

## 6. `alloc_v2.ts` — the client

One file, synchronised byte-for-byte across nine apps at
`<app>/resources/js/checkpoint/alloc_v2.ts` (`afsserver/bin/sync-checkpoint-client.sh`).
There is no shared package because these are nine separate repos.

### 6.1 Construction

Each app has a factory — `createAllocV2()`, usually in `resources/js/registry.ts`:

```ts
new CheckpointAllocV2({
    applicationId: import.meta.env.VITE_CHECKPOINT_APP_ID,
    serverURL:     import.meta.env.VITE_CHECKPOINT_SERVER_URL,
    memberReferenceNum: app.window.member?.reference_num ?? '',
    tier:          Number(app.window.member?.membership ?? 0),
    registryUrl:   import.meta.env.VITE_CHECKPOINT_REGISTRY_URL,
    bypass:        Number(app.window.member?.level ?? 0) === 99,
    getToken:      () => /* portal bearer token */,
});
```

Notes on three of those:

- `tier` is coerced with `Number()` because `member.membership` arrives as a string from
  some payload shapes, and a string index resolves every limit to 0.
- `bypass` is passed **into the client** rather than checked at each call site. There are
  three call sites per resource across every app; a bypass that must be remembered at each
  is one that will be missed at one, giving a staff account a limit dialog nobody can
  explain. Under bypass, `canUse` always allows and `consume`/`release` write nothing — a
  staff member reproducing a bug must not burn a real member's quota.
- `bypass` tests `level === 99` **exactly**, never `>= 99`. `level` defaults to 0 and has no
  documented ladder above it, so 99 is a sentinel, not a rank.

### 6.2 The public surface

| Method | Purpose |
|---|---|
| `getAll(forceRefresh?)` | Every resource, every scope instance, in **one indexed query**. Cached. |
| `get(resourceId, scope?)` | One resource. Never cached — always reads fresh. |
| `canUse(resourceId, amount?, scope?)` | Read-only preflight. Returns `{ available, remaining, limit, used, unlimited }`. |
| `consume(resourceId, amount?, options?)` | Atomic increment. Throws `ALLOC_V2_LIMIT_EXCEEDED` on a `block` resource at the cap. |
| `release(resourceId, amount?, options?)` | Atomic decrement, clamped so a counter never goes negative. |
| `getRemaining` / `getLimit` / `getUsage` / `isUnlimited` | Convenience reads over `get()`. |
| `invalidateCache()` | Drop the memoised `getAll()` result. |

Module-level exports worth knowing: `ledgerKey()`, `scopeDescriptor()`,
`periodHasRolledOver()`, `resolveLimit()`, `ensureParseInitialised()`, and the
`AllocV2Error` class with its `code` / `statusCode`.

### 6.3 Scope, and the binding table

Scope is expressed as an options object, not a positional argument:

```ts
await allocV2.canUse('domain_email_accounts', 1, { domain: 'example.com' });
await allocV2.consume('domain_ecommerce_items_per_store', 1, { domain: 'example.com', store: 'main' });
```

`AllocV2Scope` has six fields — `domain`, `pm_project`, `store`, `dreamgraph_graph`,
`pdf_document`, `design_project` — and a single table maps them to the registry's scope
names and container keys:

```ts
const SCOPE_BINDINGS = [
    { field: 'store',            scopeName: 'per_store',          container: 'stores',               parent: 'domain' },
    { field: 'domain',           scopeName: 'per_domain',         container: 'domains'               },
    { field: 'pm_project',       scopeName: 'per_project',        container: 'pm_projects'           },
    { field: 'dreamgraph_graph', scopeName: 'per_graph',          container: 'dreamgraph_graphs'     },
    { field: 'pdf_document',     scopeName: 'per_document',       container: 'pdf_editor_documents'  },
    { field: 'design_project',   scopeName: 'per_design_project', container: 'design_flow_projects'  },
];
```

Two properties of this table matter:

- **It is ordered most-specific-first.** A `per_store` scope also carries `domain`, so
  `store` must match before `domain` does, or every store counter would resolve to its
  domain's.
- **It is the only place the mapping exists.** Every read, write and rollover path resolves
  through it rather than re-deriving the mapping with its own
  `if (scope.domain) … else if (scope.pm_project) …` chain. That chain shape is what leaves
  scopes unimplemented in one place out of ten.

`store` requires `domain`, because a store name is unique only within its domain. In
`getAll()` the stores map is keyed `"<domain>/<store>"` for the same reason.

### 6.4 How a read works

`getAll()` issues **one** query — every row for the member — and groups it by
`scope_type`/`scope_key`. The **registry** then drives which resources appear, so a resource
the member has never touched still reports its limit with `used: 0` instead of being absent
from the response. Instance-scoped sections are driven by the rows, because there is no
meaningful entry for a domain that does not exist.

A period counter whose `period_start` falls outside the current period reads as **0**, and
that is applied on the **read** path. So status tells the truth on the 1st of the month even
if nothing has consumed yet to trigger the reset.

### 6.5 How a write works, and the two honest limitations

`consume()`:

1. Resolve the limit from the registry for this tier.
2. `fetchOrCreateRow()` on the ledger key.
3. Read `used`. Pre-check: would `used + amount` exceed the limit?
4. `row.increment('used', amount)` and save — one atomic `$inc`.
5. Post-check. If a concurrent racer pushed it over, apply a **compensating decrement of
   the amount we added** and throw.

Step 5 compensates by the delta, never by setting the counter back to the value read in
step 3 — that would silently discard whatever the racer consumed.

The two limitations, stated plainly:

1. **The limit check cannot be atomic with the increment.** `increment()` has no conditional
   form. Two clients racing exactly at the boundary can both briefly write; the counter
   converges via the compensating decrement, but a cap that must never be exceeded even
   transiently needs server-side Cloud Code.
2. **`release()` clamps the delta, not the result** — `-min(amount, used)` — so a release
   larger than the recorded usage drives the counter to 0 rather than negative. A negative
   counter would let the member exceed the tier limit by exactly that amount.

---

## 7. The server side

Three PHP surfaces speak the same storage contract. They exist separately because they are
separate Laravel repos with no shared package — the same reason `alloc_v2.ts` is file-synced
rather than imported.

### 7.1 `AllocationV2Controller` (auth) — the HTTP API

Routes under `/checkpoint/alloc/v2`:

| Route | Action |
|---|---|
| `GET /` | Full allocation status |
| `GET /{resourceId}` | One resource |
| `POST /check` | Read-only preflight |
| `POST /consume` | Increment |
| `POST /release` | Decrement |

Identity comes from the request: `member_reference_num` and `tier`. Scope arrives as query
or body parameters covering all seven scopes. Authorisation is a system key header
(`X-Checkpoint-System-Key` / `X-Appfactory-System-Secret`) or a Checkpoint session.

Its `consume` mirrors the client's exactly: `fetchOrCreateRow` → read → limit check →
`increment` → post-check with a compensating decrement. Reads never write — `GET` does not
"synchronise" anything.

### 7.2 `AllocationGateway` (auth) — for Checkpoint's own endpoints

Used by `SecondaryUserService` for the `secondary_users` quota on
`/checkpoint/secondary/register`. Uses the Parse **PHP SDK with the master key**, because
`auth` legitimately holds that secret and has direct PDO access to `brain_memberships`.

It is member-scoped by construction: every row it touches has `scope_type: member` and empty
scope key and parent.

### 7.3 `ParseAllocationGateway` (coordinator) — for afs-connect

Same contract, over **REST with the Application ID only** — coordinator holds no master key
and therefore has no more authority than the browser client. That is a deliberate per-app
trust-level difference, not an inconsistency.

It implements an interface:

```php
public function isEnabled(string $memberReferenceNum, string $resourceId): bool;
public function check(string $memberReferenceNum, string $resourceId, int $amount = 1): AllocationVerdict;
public function consume(string $memberReferenceNum, string $resourceId, int $amount = 1): AllocationVerdict;
public function release(string $memberReferenceNum, string $resourceId, int $amount = 1): void;
```

Note what is **not** in those signatures: a tier. The tier is never accepted from a caller —
it is always re-derived server-side from `BrainMembershipLookup`. A caller that could name
its own tier could name tier 4.

Two behaviours worth copying:

- **Fail safe, not open.** On transport failure a `block` resource **denies**; a `notify`
  resource allows, flagged degraded. Parse being briefly unreachable must never become free
  quota, and must not break read-only browsing either.
- **Three-valued row reads.** `fetchRow()` returns the row, `null` for "no row, which is
  genuinely zero usage", or `false` for "could not ask". Collapsing the last two is how an
  outage becomes a full allowance.

### 7.4 `MemberInitController` (auth) — provisioning

`POST /checkpoint/member/init` with `{ "member_reference_num": "<ref>" }` creates **exactly
the 14 member-scoped rows**, derived from the registry.

It returns nothing the caller has to persist. The rows are stored under the reference the
caller already supplied, so there is no id to hand back and nothing to keep in step.

**Member scope is eager; the other six scopes are not.** A member-scoped row's set is fixed
and known at signup, and describes the member rather than a thing the member owns. The
scoped rows are created by `fetchOrCreateRow()` on first use, because at signup there is no
domain, store, project, graph, document or design project to key them on — and a placeholder
instance would count toward the member's own allowance and be walked by the retention
cascade.

The whole call is idempotent: re-calling it returns the existing anchor and creates only
missing rows.

`parse_member_query_v4.json` documents what this writes. It is **generated**
(`php artisan checkpoint:build-member-template`) and guarded by
`CheckpointTemplateConsistencyTest`. It is documentation, not a payload — nothing is copied
from it into Parse, so there is exactly one authority (the registry) rather than two files
that have to agree.

### 7.5 `BrainAllocationController` (www) — operator edits

| Route | Action |
|---|---|
| `GET /portal/api/v1/brain/member/{ref}/allocation` | Every ledger row for the member |
| `POST /portal/api/v1/brain/member/{ref}/allocation` | Apply changes |

Talks to Parse over REST with the master key. Reading through the API rather than
browser-direct means the allocation class permissions can be closed without breaking the
admin UI.

A change addresses a **row**, not a path:

```json
{
  "changes": [{
    "ledger_key":     "m1xJppRmdtH2Y|domain_email_accounts|per_domain||example.com",
    "field":          "used",
    "before":         3,
    "after":          5,
    "row_updated_at": "2026-08-30T18:41:19.104Z",
    "path":           "/domains/0/resources/domain_email_accounts/used"
  }],
  "reason": "…", "mode": "form", "force": false
}
```

`path` is carried for the diff dialog and the audit row only — it is far more readable to an
operator reviewing a trail than a pipe-delimited key — and nothing is resolved from it.

Four rules the endpoint enforces:

- **A closed set of editable fields**: `used`, `retired`, `state`, `purge_after`,
  `period_start`, `limit_override`. `ledger_key`, `resource_id`, `scope_*`,
  `member_reference_num` are *identity*: editing one would not move a
  number, it would re-point the row at a different counter and orphan the original.
- **Concurrency is per row.** Each change carries its own row's `updated_at`. Two admins
  editing different counters do not collide, and a member consuming storage in their own
  browser does not conflict a pending edit to their email quota.
- **Validate everything, then write.** A half-applied allocation edit is worse than a
  rejected one, because the operator cannot tell which half landed.
- **A decrease requires a reason** — including a `state` change away from `active`, which
  removes something the member currently holds. Forced saves also require one, and are
  recorded as forced.

The editor (`brain_membership.ts`) projects the flat row list into a nested view model for
its form and JSON views, and translates edits back into per-row operations. That projection
exists only for the duration of an edit; storage is the rows.

---

## 8. How a feature uses the system

The pattern is three calls at three moments. From `portal/admin/views/email.ts`:

**1. Before offering the action** — read-only, decides whether the UI is usable:

```ts
const result = await allocV2.canUse(
    RegistryResourceIds.DOMAIN_EMAIL_ACCOUNTS,
    1,
    { domain: quotaDomain }
);
if (!result.available) {
    showEmailAccountLimitCover('You have reached your email account quota …');
}
```

**2. After the thing actually exists** — record it:

```ts
if (isNewAccount && allocV2 && quotaDomain) {
    allocV2.consume(
        RegistryResourceIds.DOMAIN_EMAIL_ACCOUNTS,
        1,
        { domain: quotaDomain }
    );
}
```

**3. After it is deleted** — give the slot back:

```ts
await allocV2.release(RegistryResourceIds.DOMAIN_EMAIL_ACCOUNTS, 1, { domain: quotaDomain });
```

### 8.1 Rules for wiring a feature

- **Consume *after* the real thing exists, not before.** If creation fails after a consume,
  the member has paid for something they do not have.
- **`canUse` is a check, not a reservation.** It narrows the race; it does not hold a slot.
  `consume` does the authoritative check, and it is `consume` that throws.
- **Handle the throw.** `consume()` rejects with `AllocV2Error` carrying
  `ALLOC_V2_LIMIT_EXCEEDED`. A `catch` that logs and continues is a quota that does not
  exist — the check must **fail closed**.
- **Use the resource-id constants** (`RegistryResourceIds` / `AllocV2Resources`), never a
  string literal. A typo resolves to no registry entry, which resolves to limit 0.
- **Pass the full scope.** For `per_store` that means `{ domain, store }`, not `{ store }`.
- **Release on delete.** Without it, quota is a one-way ratchet: after a downgrade a member
  who deletes things to get under the new limit still cannot create.

### 8.2 Server-side features

A feature living on a server calls a gateway instead, with the same shape — `check` before,
`consume` after, `release` on delete. `SecondaryUserService` is the worked example:
`check()` before creating a secondary user, `consume()` after, `release()` on removal.

### 8.3 Reconciliation

`php artisan alloc:reconcile-payments [--dry-run] [--fix] [--member=]` compares real SQL row
counts against the ledger for the payments resources and reports or corrects drift. It is
the template for per-resource reconcilers; the registry's `inventory` field names the app
and table that owns the real items for each resource, which is what such a reconciler needs.

---

## 9. Reading the whole thing at once

A concrete trace, for "member `m1xJppRmdtH2Y` adds an email account to `example.com`":

1. The browser has `member.reference_num = m1xJppRmdtH2Y` and `member.membership = 2`.
2. `createAllocV2()` builds a client pointed at the Parse host and the registry URL.
3. `canUse('domain_email_accounts', 1, { domain: 'example.com' })`:
   - fetch + memoise the registry;
   - `tiers[2].value` → **50**, `enabled: true`;
   - `scopeDescriptor({domain})` → `per_domain` / `example.com` / `''`;
   - `ledgerKey(...)` → `m1xJppRmdtH2Y|domain_email_accounts|per_domain||example.com`;
   - query `MemberResource` on that key → `used: 3`;
   - `3 + 1 <= 50` → `{ available: true, remaining: 47, limit: 50, used: 3 }`.
4. The UI enables the form. The mailbox is created in `www/users_email`.
5. `consume('domain_email_accounts', 1, { domain: 'example.com' })` → find-or-create the same
   row → `increment('used', 1)` → `used: 4`.
6. `getAll()` on the next page load returns
   `status.domains['example.com'].domain_email_accounts = { limit: 50, used: 4, remaining: 46 }`.

Nothing in step 5 wrote a limit, and nothing in step 3 wrote anything at all. The policy
lives in one file; the fact lives in one row.

---

## 10. Where this is going

The forward edge is built. The backward edge — what happens to items already held when the
plan shrinks — is designed but mostly unimplemented, and is what `membership_change/`
covers. The row fields that look unused today (`retired`, `state`, `retired_at`,
`purge_after`, `frozen_remaining_s`) exist because that is the shape it needs.

Note that `alloc-v2-parse-direct/03` §3.2.3 still describes `MemberRegistry` as keeping a
readable `used` "display projection". That was superseded twice over: first by the row
model, which removed the projection (and with it allocation.md's **G13**), and then by
removing the class outright. Read it as history.

`TODO-member-resources.md` tracks it resource by resource, with the cross-cutting blockers
that have to close first. The short version: two of 34 resources have both edges, and the
per-resource work is mostly about identifying which app and table actually owns each
inventory — because you cannot retire an item you cannot find.
