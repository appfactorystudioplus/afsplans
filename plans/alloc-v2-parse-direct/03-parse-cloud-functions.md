# 03 — The Parse Side: Closed Writes and Two Functions

> **Rewritten 2026-08-31, against the deployed code and the live Parse schema.**
>
> The previous version of this document specified a new class called
> `MemberRegistryCounter` and a `MemberRegistry` anchor object that kept a readable `used`
> for display. Neither describes what exists. The counter class was built as
> **`MemberResource`** and is live with real data; **`MemberRegistry` has been deleted
> outright** — class, objects, and the `brain_memberships.parse_alloc` column that pointed
> at it. §3.9 records the full delta and why each part moved.
>
> What has *not* changed is the reason this document exists: **the increment still cannot be
> made safe from a client**, and **the counter class is still world-writable**. Those are
> §3.1's P1 and P2, and both are still open.

Location: `w/auth/public/checkpoint/parse/` — `config.ts` and `cloud/functions.ts`.

---

## 3.1 What this document is for

Two problems. One small piece of work.

| # | Problem | Closed by |
|---|---|---|
| **P1** | Two concurrent requests both pass the limit check, both write, and the member ends up over the ceiling | §3.4, the conditional write |
| **P2** | Anyone holding the application id, which ships in every browser bundle, can rewrite or delete any member's counters | §3.3, closing write permissions |

Everything else stays where it already is. `alloc_v2` still reads
`checkpoint_registry_v4.json`, still resolves the tier, still decides whether the member may
create one more, still owns the limit dialog and the upgrade prompt. **Parse remains a
counter store.** The only thing that moves into cloud code is the increment, because the
increment is the one step that cannot be made safe from a client.

### 3.1.1 Why the increment cannot be made safe from a client

The Parse SDK can read and it can write. It has no *"write only if"*. So a client-side check
and the write that follows it are always two round trips with a gap between them:

```
tab A: read 9   ->  9 < 10, allowed
tab B: read 9   ->  9 < 10, allowed
tab A: write 10
tab B: write 11        <- over the limit, and nothing failed
```

Both clients read live, correct, non-stale values. Reading more often does not help — the gap
between the read and the write is the defect, not the freshness of the read. A cloud function
closes it because the compare and the write happen inside one call.

**This is the whole reason cloud code appears in this plan.** It is not a policy engine and
it is not an application server. It is the one place a conditional write can be expressed.

> **The row model already fixed the *lost update*, which is a different bug.** `used` is a
> top-level field on its own object now, so `increment()` is a real Mongo `$inc` and two
> concurrent consumes both land. What it cannot do is *refuse* — `$inc` has no condition. So
> the counter is correct but the ceiling is not enforced, which is precisely P1 and precisely
> what is left.

## 3.2 The counter class — `MemberResource`, as built

### 3.2.1 The problem this already solved

Usage used to live nested inside a single per-member document:

```
domains[1].resources.email_accounts.used
```

Incrementing that needs a dot path, and parse-server rejects dots in field names before the
op reaches Mongo (`SchemaController.js`, `/^[A-Za-z][A-Za-z0-9_]*$/`). So the only available
operation was read-whole-object, change a number, write-whole-object back — and the second
of two concurrent writers silently erased the first. The `ac_*` flat-field workaround needed
a column per (scope × resource), an open set that cannot be declared against
`lockSchemas: true`, so it fell back to the nested write and lost atomicity anyway.

**That is done.** One row per counter, `used` at the top level, `$inc` applies. This section
is kept only so the next reader knows why the shape is what it is.

### 3.2.2 The live shape

Verified against the deployed schema, not the plan that proposed it:

| Field | Meaning |
|---|---|
| `member_reference_num` | Owning member, and the only member identity there is |
| `resource_id` | Registry key, e.g. `domain_email_accounts` |
| `scope_type` | `member` \| `per_domain` \| `per_store` \| `per_project` \| `per_graph` \| `per_document` \| `per_design_project` |
| `scope_key` | Instance name; empty for member scope |
| `scope_parent` | Parent instance for a nested scope (`per_store` under a domain); empty otherwise |
| `ledger_key` | `member｜resource｜scope_type｜scope_parent｜scope_key` — **the identity** |
| `used` | The atomic increment target |
| `retired`, `state`, `retired_at`, `purge_after`, `frozen_remaining_s` | Retention, for `membership_change/` |
| `period_start` | Period resources only — see §3.2.3 |
| `limit_override` | Per-row override; null means the tier allowance applies |

Four implementations construct `ledger_key` and they must stay byte-identical:
`alloc_v2.ts`, `AllocationV2Controller`, `MemberInitController`, and the two PHP gateways.
A cloud function makes a **fifth**. That is the single highest-risk detail in this document —
a divergence does not throw, it silently creates a second row for the same counter.

### 3.2.3 Period is a FIELD here, not part of the identity — and that is a real difference

The previous draft made `period` part of the row identity, so a new month was a new row at
zero and rollover logic disappeared. **The built model did not do that.** `period_start` is a
field on one permanent row, and rollover is computed on read:

> A counter whose `period_start` falls outside the current period reads as **0**. A write
> that lands after a rollover **sets** `used = amount` and re-stamps `period_start`, rather
> than incrementing.

Both designs are defensible. The built one is live in four implementations with 62 rows
behind it, so **the cloud function must implement the built semantics**, not the plan's.
`AllocationGateway::increment()` in `auth` is the reference implementation — read it before
writing the function, and copy its branch exactly.

This matters more than it looks: get it wrong and a monthly counter either never resets or
resets on every call, and both failures are invisible until a month boundary.

### 3.2.4 The unique index is REQUIRED — measured, not assumed

The previous draft leaned on a unique index over the identity columns to arbitrate lazy row
creation — two first-uses racing to create the same counter, one wins, the other retries. This
document previously downgraded that to "correct but unenforced", on the reasoning that
find-or-create with a re-query on failure was sufficient in practice.

**That reasoning is wrong, and there is now a measurement.** Against a real Parse instance,
200 concurrent first-consumes of a counter that did not yet exist:

| | Rows created | Final `used` |
|---|---|---|
| **Without** a unique index on `ledger_key` | **42** | **159** |
| **With** `{ ledger_key: 1 }, { unique: true }` | **1** | **200** |

The counter split 42 ways and lost 41 increments. Find-or-create in application code cannot
prevent this: every racer queries, finds nothing, and creates — the window is between the read
and the insert, and only the database can close it.

> **The 50-concurrent test in §3.10 passes WITHOUT the index.** The failure only appears above
> some concurrency threshold, which is exactly what makes it dangerous: a smaller test says
> everything is fine, and the defect surfaces later as a member whose quota is silently wrong.
> Do not treat a passing low-concurrency run as evidence.

`parse-server`'s schema `indexes` block creates the index but **cannot express uniqueness**, so
declaring the class (§3.8 step 1) is necessary but not sufficient. The constraint has to be
created directly:

```js
db.MemberResource.createIndex({ ledger_key: 1 }, { unique: true, name: 'ledger_key_unique' })
```

Run it against a database with no duplicates or it fails — which is itself the check worth
running first. **Until it exists on a given deployment, that deployment can split counters
under load**, and the find-or-create compensation in every implementation is the only (partial)
mitigation: keep it, and do not "simplify" it away once the index lands.

## 3.3 Class-level permissions — and exactly what closing them breaks

**Live state, read from the server 2026-08-31:**

```
MemberResource        public: ACL, addField, count, create, delete, find, get, update
CheckpointAlloc_Log   public: ACL, count, create, delete, find, get, update
Registry              public: count, create, delete, find, get, update
```

`MemberResource` is world-writable **and world-deletable**, and it is now the only class
holding allocation data. Anyone with the application id — which ships in every bundle — can
zero a member's usage or delete the rows outright. This is P2, and it got sharper rather than
softer when the anchor was removed, because everything now lives in one public class.

### 3.3.1 Writes close. Reads do not — and here is why

The previous draft could close the counter class completely, because `alloc_v2` read usage
from the member object instead. **There is no member object any more.** `getAll()`, `get()`,
`canUse()` and the Brain editor all read `MemberResource` rows directly. Closing `find` and
`get` would break every read in the estate.

So this pass closes exactly the write half:

```ts
/**
 * MemberResource — writes closed, reads left open.
 *
 * Cloud Functions use the master key and are unaffected by CLPs, so closing
 * writes costs the legitimate path nothing: after §3.8 step 4 the ONLY client
 * write path is allocConsume / allocRelease.
 *
 * READS ARE DELIBERATELY STILL OPEN, and this is not an oversight:
 *   - getAll() issues a `find` filtered on member_reference_num
 *   - get() / canUse() issue a `find` on ledger_key
 *   - the Brain editor reads rows through the www API (master key) and is fine
 *
 * Closing reads therefore needs a read-side function (allocStatus/allocRead)
 * and a client change in nine copies. That is a separate pass with its own
 * blast radius — see §3.3.2. Leaving reads open is the state today; this pass
 * does not make it worse, and it does not fix it either.
 */
const CLP_MEMBER_RESOURCE = {
  find:     { '*': true },   // getAll() and get() — see above
  count:    { '*': true },
  get:      { '*': true },
  create:   {},              // ← allocConsume creates rows
  update:   {},              // ← allocConsume / allocRelease increment
  delete:   {},              // ← nothing should ever delete a counter from a client
  addField: {},              // ← the schema is declared; clients do not extend it
};

/**
 * CheckpointAlloc_Log — append-only audit, closed to clients entirely.
 *
 * The log class EXISTS and alloc_v2 already writes it client-side
 * (writeAuditRow()). A client-written audit trail is worth very little: the
 * same caller that can forge a consume can forge its record. Once the
 * functions own the write (§3.4), they own the log entry too, and this closes.
 */
const CLP_ALLOC_LOG = {
  find: {}, count: {}, get: {}, create: {}, update: {}, delete: {}, addField: {},
};
```

### 3.3.2 The read exposure, stated so it is not forgotten

With `find` open, a caller holding the application id can enumerate `MemberResource` and read
**any** member's usage. That is true today. It is not new, and this pass does not change it.

Closing it properly means:

1. an `allocStatus` function returning the member's own rows, resolved from the token;
2. `getAll()` / `get()` / `canUse()` rewritten onto it in nine copies;
3. the Brain editor unaffected (it already goes through www's master-key API).

That is a bigger change than this document, and it should not be smuggled into it. **Record
it as open.**

### 3.3.3 `Registry` — declared, live, public, and dead

`config.ts` declares exactly one class, `Registry`, with `name`, `domains`,
`domain_processors`, `sync_storage` and friends. That is the pre-v2 allocation scheme. Its
last consumers — the `MemberRegistryManager` layer in all eight `registry.ts` files — were
deleted on 2026-08-31. **Nothing reads or writes it.**

It should be dropped from `config.ts` and from the server. It is listed here rather than in
the order of work because deleting a class with unknown contents is a one-way operation:
check it is empty first, then drop it.

## 3.4 The two functions

```ts
/**
 * allocConsume — record consumption, atomically, against a caller-supplied limit.
 *
 *   { resource_id, scope_type, scope_key, scope_parent, amount, limit }
 *     -> { ok: true,  used }                            consumed
 *     -> { ok: false, used, reason: 'limit_exceeded' }  refused, nothing written
 *
 * WHAT THIS FUNCTION DOES NOT DO, deliberately: it does not read the registry,
 * it does not resolve a tier, it does not know what a plan is, and it does not
 * decide whether the member SHOULD be allowed to create something. alloc_v2
 * does all of that and passes the resulting ceiling down as `limit` (§3.5).
 *
 * `limit` semantics, and only these:
 *   n >= 0   consume only if the result stays <= n
 *   -1       unlimited: count, never gate
 */
Parse.Cloud.define('allocConsume', async (req) => { … });

/**
 * allocRelease — return units to the pool on a delete, an undo, a cancellation.
 *
 *   { resource_id, scope_type, scope_key, scope_parent, amount } -> { ok: true, used }
 *
 * Clamps at zero. A release larger than current usage sets the counter to 0
 * rather than negative — negative usage reads as free capacity forever and is
 * never a legitimate state.
 */
Parse.Cloud.define('allocRelease', async (req) => { … });
```

### 3.4.1 The write itself

> Find the row for `ledger_key`; if there is none, create it at 0; then add `amount` to
> `used` **only if the result stays within `limit`**; return the new value, or refuse.

Two implementations are available and only one should be built.

| | How | Cost |
|---|---|---|
| **(a) conditional update** | Reach the Mongo driver and issue `findOneAndUpdate({ ledger_key, used: { $lte: limit - amount } }, { $inc: { used: amount } })`. `null` back means the ceiling was reached | No window at all, one round trip. But it is an escape hatch out of Parse, maintained inside cloud code on a host with no CI and no test suite |
| **(b) increment then verify** ✅ | `increment(amount)` → `save({ useMasterKey })` → read the value Parse returned **to this caller** → if it exceeds `limit`, `increment(-amount)`, save, and refuse | Pure Parse SDK. A transient overshoot other callers could observe, one compensating write, and a crash between the two leaves the counter high until a reconciler corrects it |

**Build (b).** Member allocation is human-paced — a member adds a domain, creates a form,
adds a mailbox. Concurrency here is double-clicks, retries and two open tabs, not throughput.
(a) buys a zero-width window this workload does not need, at the price of a raw-driver
dependency inside cloud code.

> ### The detail that makes (b) correct rather than subtly broken
>
> Use the post-increment value **on your own object instance**, the one Parse hands back from
> `save()`. Do **not** re-query the row to check.
>
> With `used = 9, limit = 10` and two concurrent callers: A's save returns 10, B's returns 11.
> A sees `10 <= 10` and accepts. B sees `11 > 10`, decrements, and refuses. Final state 10 —
> correct.
>
> Re-read the row instead and both callers see 11, both roll back, and you have refused an
> allocation that should have succeeded. The per-caller return value **is** the serialisation.

Row creation is the same statement's problem: create-if-absent and tolerate losing the race
by re-querying `ledger_key`. Once §3.8 step 1 adds the unique index, the loser takes a
duplicate-key error instead of silently creating a second row — which is the point of adding
it.

**Period rollover rides along with the increment**, per §3.2.3: if `period_start` is outside
the current period, `set('used', amount)` and re-stamp rather than incrementing. One save,
so the reset cannot be lost between two operations.

### 3.4.2 Identity: the one thing the caller may not assert

A closed CLP stops direct writes. It does not stop someone calling `allocConsume` with
somebody else's member reference, and a function that accepts the member as a parameter
re-opens P2 through its own front door.

So the function resolves the caller and **ignores any member reference in the payload**:

```ts
/**
 * The caller forwards its Checkpoint access token; this validates it against
 * Checkpoint and takes `member_reference_num` from the RESULT.
 *
 * IDENTITY ONLY. No tier, no plan, no entitlement — those stay in alloc_v2
 * (§3.5). This is the minimum needed to answer "whose counters may I touch".
 *
 * Cached 60s against a HASH of the token, never the token itself: one user
 * action can fire several consumes and should not become several Checkpoint
 * round trips.
 *
 * Checkpoint's attachMember() only hard-fails in production, so a memberless
 * identity CAN validate outside it. Refuse it here rather than assuming the
 * identity provider did.
 */
```

**This introduces a dependency on Checkpoint in the allocation write path.** State the
failure posture explicitly: **Checkpoint unreachable ⇒ the function refuses.** Denying a
create is recoverable; a forged consume is not.

Note the asymmetry recorded in `../handoff.md` §9 — *deny on unknown* is right here and
**catastrophic on the retirement edge**, where a missing membership row must cause a holder
to be skipped rather than treated as plan 0. The two must not share a helper.

### 3.4.3 What this fixes, and what it does not

| | |
|---|---|
| Races between honest clients | ✅ fixed — this is P1 |
| A caller writing or deleting the counter directly | ✅ fixed by §3.3 — this is P2 |
| A caller **reading** another member's counters | ❌ not fixed — §3.3.2, needs its own pass |
| A caller passing `limit: 999999` to a real function call | ❌ **not fixed**, deliberately |

The last is deliberate. `../allocation.md` §7 already classes allocation as advisory quota
management rather than a security boundary. **The upgrade path is one change, later:** the
function derives the limit itself — registry lookup plus a server-derived tier — instead of
trusting the one it was given. Do not build it now; deriving policy inside Parse is what
turned an earlier draft into a policy engine.

## 3.5 What stays in `alloc_v2` — which is nearly all of it

Unchanged: reads `checkpoint_registry_v4.json`; resolves the tier and the positional
`tiers[tier]` entry; distinguishes `enabled: false` from a reached limit (`../allocation.md`
**G7**, still open); reads current usage for display; owns `canUse`, the limit dialog, the
upgrade prompt and the level-99 bypass; honours `overage: notify` by warning rather than
blocking.

Changed — two methods:

| Method | Is now | Becomes |
|---|---|---|
| `consume()` | `fetchOrCreateRow` → `increment` → `save` → post-check → compensating decrement | `Parse.Cloud.run('allocConsume', { resource_id, scope_type, scope_key, scope_parent, amount, limit })` |
| `release()` | `fetchRow` → clamped `increment` → `save` | `Parse.Cloud.run('allocRelease', { … })` |

Every public method keeps its exact name, parameters, return type and error type, so call
sites compile untouched.

`canUse()` stays a client-side read and stays advisory. It gates the UI; `allocConsume` is
the authority. A `canUse` that passes and a `consume` that then refuses is **correct
behaviour**, not a bug — someone else consumed in between — and the UI has to be able to
show that outcome.

The client's own compensating-decrement logic comes **out** when the function goes in. Two
layers both compensating for the same overshoot would double-refund.

## 3.6 Every writer moves, or the lock breaks one of them

There are **three** independent write paths into `MemberResource`, not two:

| Writer | Where | Credential |
|---|---|---|
| `alloc_v2.ts` (browser) | nine byte-identical copies | application id — **breaks when §3.3 lands** |
| `ParseAllocationGateway.php` | `coordinator/app/Services/AfsConnect/Allocation/` | application id, REST — **breaks when §3.3 lands** |
| `AllocationV2Controller.php`, `AllocationGateway.php`, `MemberInitController.php`, `BrainAllocationController.php`, `AllocReconcilePayments.php` | `auth` and `www` | **master key — unaffected** |

Only the first two must move onto the functions before §3.3. The master-key callers bypass
CLPs entirely and keep working; whether they *should* also route through the functions is a
consistency question, not a correctness one, and is out of scope here.

Coordinator's gateway is server-side and holds no Checkpoint user token, so §3.4.2 needs a
second accepted credential for it — the service-key pattern
`../afs-connect/06-security-and-service-auth.md` already uses, **with its own key, not one
shared across callers**.

## 3.7 CORS — the allowlist, and where it actually lives

Going direct means every one of the seven web apps makes a **cross-origin** request to the
registry host. Native clients (desktop, mobile, CLI) send no `Origin` header and are
unaffected — this section is entirely about browsers.

**This section is unchanged and still accurate.** It is independent of everything above: it
needs server access to the registry host and no code change in any app.

### 3.7.1 CORS is enforced in Nginx, not in Parse

`parse/index.ts` imports `cors` and then **deliberately does not use it**:

```ts
// NOTE: CORS is handled entirely by Nginx (proxy_hide_header strips the
// wildcard header Parse Server emits, then Nginx injects the correct
// origin-specific header). Do NOT add cors() middleware here — it would
// create duplicate Access-Control-Allow-Origin headers that browsers reject.
```

- **Do not uncomment it.** Two `Access-Control-Allow-Origin` headers is not "more
  permissive", it is a hard browser failure — and the symptom (every request blocked, from
  every origin) looks identical to a missing allowlist, which is how an afternoon disappears.
- **The change is an Nginx vhost change on `dashboard15-registry.appfactory.studio`.**

### 3.7.2 The allowlist

| App | Development origin | Production origin |
|---|---|---|
| members | `https://members15.appfactory.studio` | `https://members.appfactory.studio` |
| coordinator | `https://coordinator.dev.appfactory.studio` | `https://coordinator.appfactory.studio` |
| dream_graph | `https://graphs.dev.appfactory.studio` | `https://graphs.appfactory.studio` |
| notes | `https://notes.dev.appfactory.studio` | `https://notes.appfactory.studio` |
| pdf_editor | `https://pdfeditor.dev.appfactory.studio` | `https://pdfeditor.appfactory.studio` |
| flow | `https://flow.dev.appfactory.studio` | `https://flow.appfactory.studio` |
| www | `https://www15.appfactory.studio` | `https://www.appfactory.studio` |
| auth *(Checkpoint UI)* | `https://auth15.appfactory.studio` | `https://auth.appfactory.studio` |

Plus local development: `http://localhost` and `http://127.0.0.1` **on any port**, and
`https://localhost` for anyone running TLS locally.

> **Added 2026-08-31.** Local development in this checkout runs on Laragon `.test` vhosts —
> `https://www.test`, `https://auth.test`, `https://members.test`, `https://coordinator.test`
> and the rest. If browser work is ever done against the live registry from those origins,
> they need adding too. They are deliberately **not** in the list above, because they are one
> machine's hosts file rather than a deployed environment.

Two adjacent hosts exist in Checkpoint's own `sso.allowed_domains` and are **not** alloc_v2
consumers today — `market{,15}.appfactory.studio` and `joystick15.appfactory.studio`. Leave
them out until something in them actually calls allocation.

### 3.7.3 Four anomalies found while collecting these

1. **Two dev naming conventions coexist.** `members15` / `www15` / `auth15` use a numeric
   suffix; `coordinator.dev` / `graphs.dev` / `notes.dev` / `pdfeditor.dev` / `flow.dev` use
   a `.dev.` subdomain. Both must be in the allowlist because both are real.
2. **`flow/_env/dev/.env` has `APP_URL=https://flow.appfactory.studio`** — the *production*
   host in the dev file.
3. **`auth/_env/prod/.env` has `APP_URL=https://auth15.appfactory.studio`** — the *dev* host
   in the prod file.
4. **`members` and `www` write `APP_URL` with a trailing slash.** An HTTP `Origin` is scheme
   + host + optional port and **never** has a trailing slash — so an allowlist built by
   copying `APP_URL` verbatim will silently match nothing. Normalise when generating.

### 3.7.4 Nginx configuration

```nginx
# /etc/nginx/sites-available/dashboard15-registry.appfactory.studio
#
# WHY HERE AND NOT IN THE PARSE APP: parse-server emits its own wildcard
# Access-Control-Allow-Origin. proxy_hide_header strips it, and this block
# injects the correct origin-specific value. Enabling cors() in parse/index.ts
# as well produces TWO Access-Control-Allow-Origin headers, which every browser
# rejects — and the failure looks exactly like a missing allowlist.

map $http_origin $alloc_cors_origin {
    default "";

    # ── production ────────────────────────────────────────────────────────
    "https://members.appfactory.studio"          $http_origin;
    "https://coordinator.appfactory.studio"      $http_origin;
    "https://graphs.appfactory.studio"           $http_origin;
    "https://notes.appfactory.studio"            $http_origin;
    "https://pdfeditor.appfactory.studio"        $http_origin;
    "https://flow.appfactory.studio"             $http_origin;
    "https://www.appfactory.studio"              $http_origin;
    "https://auth.appfactory.studio"             $http_origin;

    # ── development ───────────────────────────────────────────────────────
    "https://members15.appfactory.studio"        $http_origin;
    "https://www15.appfactory.studio"            $http_origin;
    "https://auth15.appfactory.studio"           $http_origin;
    "https://coordinator.dev.appfactory.studio"  $http_origin;
    "https://graphs.dev.appfactory.studio"       $http_origin;
    "https://notes.dev.appfactory.studio"        $http_origin;
    "https://pdfeditor.dev.appfactory.studio"    $http_origin;
    "https://flow.dev.appfactory.studio"         $http_origin;

    # ── local development (any port) ──────────────────────────────────────
    "~^https?://localhost(:\d+)?$"              $http_origin;
    "~^https?://127\.0\.0\.1(:\d+)?$"         $http_origin;
}

server {
    server_name dashboard15-registry.appfactory.studio;

    location /parse/ {
        proxy_hide_header Access-Control-Allow-Origin;

        # Preflight. The Parse JS SDK always sends X-Parse-Application-Id,
        # which makes every request non-simple — so OPTIONS is on the hot path.
        if ($request_method = OPTIONS) {
            add_header Access-Control-Allow-Origin      $alloc_cors_origin always;
            add_header Access-Control-Allow-Methods     "GET, POST, PUT, DELETE, OPTIONS" always;
            add_header Access-Control-Allow-Headers     "Content-Type, Authorization, X-Parse-Application-Id, X-Parse-REST-API-Key, X-Parse-Javascript-Key, X-Parse-Session-Token, X-Parse-Client-Version, X-Parse-Installation-Id" always;
            add_header Access-Control-Max-Age           86400 always;
            add_header Content-Length                   0;
            return 204;
        }

        add_header Access-Control-Allow-Origin $alloc_cors_origin always;
        add_header Vary Origin always;

        proxy_pass http://127.0.0.1:1337;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

- **No `Access-Control-Allow-Credentials`.** The Parse SDK authenticates with headers, not
  cookies, and this client forwards the Checkpoint JWT in the Cloud Function *body*.
- **An unlisted origin gets an empty `$alloc_cors_origin`**, so no header is emitted and the
  browser blocks the response — a deny, not a crash.
- **`Vary: Origin`** matters as soon as anything caches.
- **`always`** on every `add_header` — otherwise the headers vanish on 4xx/5xx and an error
  surfaces as an opaque CORS failure instead of the real status.

### 3.7.5 Ownership

- **New app origin ⇒ this file.** Adding a consumer means an Nginx edit on the registry host,
  in the same change that adds the app.
- **Checkpoint's `sso.allowed_domains` and this list overlap but are not the same thing.**
  When an app's domain changes, both need updating.

## 3.8 Order of work

Written for the state the estate is actually in on 2026-08-31.

| # | Step | Why here | Reversible? |
|---|---|---|---|
| **0** | **Put service auth on `POST /checkpoint/member/init`** | `auth/routes/checkpoint.php` puts it in a bare `Route::prefix('member')` group with **no middleware**, and `init()` saves with the master key. Anyone who knows the URL can provision ledger rows in a loop. Its own docblock says *"never directly from the browser"*; nothing enforces that | yes, one line |
| **1** | **Declare `MemberResource` in `config.ts`**, with a unique index on `ledger_key` | §3.2.2, §3.2.4. The class is currently undeclared and auto-provisioned, which is why it carries public permissions nobody chose. Note `cloud/schema.ts` is **imported by nothing** — `config.ts` line 1 is a commented-out import and it carries its own inline `schemaDef`. Editing `cloud/schema.ts` deploys nothing while the diff looks correct | yes |
| **2** | **Build `allocConsume` / `allocRelease`** | §3.4. Nothing calls them yet, so this is risk-free | yes |
| **3** | **Move `ParseAllocationGateway.php` onto the functions** | §3.6. Server-side, one app, no bundle to rebuild — do it before the browser copies | yes |
| **4** | **Move `alloc_v2.ts`'s `consume` / `release` onto the functions** | §3.5. One file, nine copies, propagated per [04](04-distribution-and-parity.md). Remove the client-side compensating decrement in the same change | yes |
| **5** | **Close the write permissions** | §3.3. The gate. Steps 3 and 4 must be **deployed everywhere** first | yes, but a mistake here breaks allocation estate-wide |
| **6** | Delete or gate `AllocationV2Controller`'s `/consume` and `/release` routes | `auth/routes/checkpoint.php` — orphans since 02 shipped, and nothing calls the whole `/checkpoint/alloc/v2` surface any more | yes |
| **7** | Drop the dead `Registry` class | §3.3.3. Verify empty first — dropping a class is one-way | no |

**Steps 0, 1 and 2 are independent of everything else and can start immediately.** Step 5 is
the only one with estate-wide blast radius, and it is safe exactly once 3 and 4 are deployed
and verified.

### 3.8.1 Deployment is the constraint, not the code

Everything in steps 1, 2 and 5 is a change to files under
`w/auth/public/checkpoint/parse/` that only takes effect **when that Parse app is deployed to
`dashboard15-registry.appfactory.studio`**. This checkout cannot deploy it.

That is not a footnote. It means:

- steps 1 and 2 can be **written and reviewed** here, but not tested here;
- step 5 must not be applied to the live schema until 3 and 4 are deployed, and the live
  schema is edited through the Parse schema API or a redeploy, not by editing `config.ts`
  alone;
- **parse-server merges schema changes rather than replacing them**, so a permissive CLP
  already in Mongo can survive a redeploy and leave you believing a lock applied when it did
  not. Verify by trying a client write, not by reading the config.

The registry file has the same problem today and is worth knowing as precedent:
`checkpoint_registry_v4.json` is correct in this repo and **404s on the deployed host**
(`00_ISSUES.md` I5).

### 3.8.2 Where the `limit` comes from — already settled, verified 2026-08-27

`alloc_v2` sends `limit` (§3.4), so it matters where that number comes from. **This is
already correct and needs no work in this plan.** Nothing bundles the registry; all eight
client factories fetch `VITE_CHECKPOINT_REGISTRY_URL` at runtime and memoise it; every app's
`.env`, `auth`'s PHP, `members`' `RegistryClient` and coordinator's gateway are all on v4,
resolved from one served file.

Two live consequences remain, and belong to
[../membership_change/06-registry-distribution.md](../membership_change/06-registry-distribution.md):

- **OQ-L is now load-bearing.** The registry is fetched and memoised for the life of the
  process, so the cache TTL is the kill-switch latency.
- **`version` is `"3.0.0"` (string) in v3 and `4` (int) in v4** — OQ-N. Fix the format before
  anything compares versions.

> **A twelfth copy was proposed and should not be built.** An earlier draft bundled the
> registry with the cloud code *"so a deploy is atomic"*. With policy in `alloc_v2`, Parse
> never reads the registry at all.

## 3.9 What changed under this plan, and what it cost

Recorded so the next reader can tell design from drift. Everything here happened **after**
the plan was written and **before** it was executed.

| Plan said | Reality | Consequence for this document |
|---|---|---|
| Create `MemberRegistryCounter` | Built as **`MemberResource`**, live, 62 rows | Class and field names throughout §3.2, §3.4 |
| `period` is part of the row identity | `period_start` is a **field**; rollover computed on read | §3.2.3 — the function must implement the built semantics |
| A unique index arbitrates row creation | **No index exists**; the class is undeclared | §3.2.4 — added in step 1; find-or-create is load-bearing until then |
| `MemberRegistry` keeps a display projection of `used` | **Class deleted**, along with `brain_memberships.parse_alloc` | §3.2.3's projection, the mirror write in §3.4, and four §3.10 rows all removed |
| Counter class fully closed; clients read the member object | There is no member object — **clients read the rows** | §3.3.1 — reads stay open, and closing them is its own pass |
| `CheckpointAlloc_Log` "deferred, not rejected" | **It exists**, and `alloc_v2` writes it client-side | §3.3's second CLP block; the functions should own the write |
| Two writers must move before the lock | **Three write paths**, but the master-key ones are unaffected | §3.6 |

**What removing `MemberRegistry` bought this plan:** the mirror write is gone, so
`allocConsume` has one job instead of two, and there is no projection that can drift
(`../allocation.md` **G13** is closed). **What it cost:** the read path moved onto the
counter class, so §3.3 can no longer close it — which is why §3.3.2 exists.

**Still true, still not fixed, and still deliberate:**

1. **A forged `limit` works** (§3.4.3). The price of leaving policy in `alloc_v2`.
2. **Reads are open** (§3.3.2). A new consequence of the anchor removal.
3. **No enforced uniqueness on `ledger_key`** until step 1 (§3.2.4).

## 3.10 Verification

> ### Results — local Parse, 2026-08-31
>
> **46 of 46 passed** against a Parse instance running from this repo's `config.ts` and
> `cloud/functions.ts`, on local MongoDB — 32 on the service-key path, 14 on the token path.
>
> Verified beyond the table below:
>
> - **P1 holds.** 50 concurrent consumes of 1 against `limit: 40` → **exactly 40**, one row,
>   40 accepted, 10 refused.
> - **A refusal leaves the counter untouched**, so the compensating decrement works.
> - **Rollover SETS rather than increments**, reuses the same row, and re-stamps
>   `period_start` — the §3.2.3 semantics, confirmed by backdating a row two months.
> - **`ledger_key` parity**: the function's algorithm reproduces all 62 live rows exactly, and
>   a drift guard asserts the tested copy still matches `functions.ts`.
> - **Rollover parity across languages**: seven boundary cases through both the TypeScript and
>   `AllocationGateway::periodHasRolledOver()` — 7 of 7 identical.
> - **`npm run build` compiles both files** against the real types and `node_modules`.
> - **parse-server created all three declared indexes** from `config.ts`.
>
> **The token path is verified too**, with a real token minted through auth's own
> `JwtService`:
>
> - **§3.4.2 holds.** A valid token for one member, sent with a payload naming a *different*
>   member, wrote nothing for the named member — the token's own counter moved. The payload
>   cannot assert identity.
> - Malformed, tampered and empty tokens are all refused, and no refused call writes.
> - `Authorization: Bearer` is accepted as well as the `token` param.
> - The 60s identity cache is real and per-token: 233ms on first use of a token, ~13ms
>   thereafter, and a token idle past its TTL re-validates.
>
> **Still unverified:** the memberless-but-valid-token refusal, which needs a Checkpoint user
> with no `brain_memberships` row.


| Check | Expect |
|---|---|
| Client-side `create` / `update` / `delete` on `MemberResource` | **denied** after step 5 |
| Client-side `Parse.Query('MemberResource').find()` | **succeeds** — deliberate, §3.3.1 |
| Client-side write to `CheckpointAlloc_Log` | denied after step 5 |
| `allocConsume` with no token / an expired token | refused |
| `allocConsume` naming another member in the payload | operates on the **token's** member, never the named one |
| Checkpoint unreachable | refused, not allowed (§3.4.2) |
| First consume of a resource with no row yet | row created, then incremented to `amount` |
| 200 concurrent first-consumes of the same counter | exactly one row exists; `used` = 200 |
| **50 concurrent consumes of 1 unit, `used` 0 → `limit` 40** | `used` lands on **exactly 40**; 10 calls refused. This is P1 and the single test that matters most |
| Consume past the limit | `ok: false`, `reason: limit_exceeded`, **counter unchanged afterwards** |
| Consume with `limit: -1` | never refuses; counts |
| Release larger than usage | clamps to 0, never negative |
| Monthly resource after a rollover | `used` **set** to `amount`, `period_start` re-stamped — one row, not two (§3.2.3) |
| Same monthly resource, same period, second call | same row incremented |
| `ledger_key` built by the function | byte-identical to `alloc_v2.ts`'s `ledgerKey()` — assert against a live row |
| Duplicate `ledger_key` insert after step 1 | rejected by the unique index |
| `ParseAllocationGateway` after step 3 | no direct `/classes/MemberResource` writes remain |
| `grep -n "increment\|\.save()" alloc_v2.ts` after step 4 | no counter writes remain; only `Parse.Cloud.run` |
| `POST /checkpoint/member/init` without a service key | **401**, after step 0 |
| Kill the unique index, rerun the 200-concurrent test | duplicate rows appear — confirms the index is what was protecting you, not luck. **Done: 42 rows, `used` 159. See §3.2.4** |
