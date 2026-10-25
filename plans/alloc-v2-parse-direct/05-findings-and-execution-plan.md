# 05 — Findings, Execution Plan, and What Was Actually Built

> ### ⚠ Status — 2026-08-31
>
> Findings re-checked against the codebase:
>
> | Finding | State |
> |---|---|
> | §5.2 atomic increment cannot work as specified | ✅ **resolved.** One row per counter; `used` is top-level; `$inc` applies. What remains is the *conditional* write — [03](03-parse-cloud-functions.md) §3.1.1 |
> | §5.3 `cloud/schema.ts` is dead code | ✅ **still true.** `config.ts` line 1 is a commented-out import and carries its own inline `schemaDef` |
> | §5.4 production Application ID mismatch | ⚠️ unverified in this pass |
> | §5.5 client duplicated eight times | **nine**, all byte-identical now |
> | §5.7 www has a live proxy route | ✅ still true — and nothing calls it. [03](03-parse-cloud-functions.md) §3.8 step 6 |
> | §5.8 the registry's `tier_resolution` convention is wrong | ✅ **fixed** in registry v4 |
>
> §5.10 and §5.11 are historical records of what was planned and built under the old scope.
> The current sequence is [03](03-parse-cloud-functions.md) §3.8.

> ## ⚠ Scope reversed, 2026-08-27 — read this before §5.10 or §5.12
>
> **The scope decision this document was written under has been reversed by the
> owner, and doc [03](03-parse-cloud-functions.md) has been rewritten.** Both the
> original decision and what replaces it are recorded below, because §5.10's plan
> and §5.11's build log were produced under the old one and are read wrongly
> without it.
>
> ### What was decided then
>
> *"The Parse server is already running and is **out of scope** — no Cloud
> Functions, no schema changes, no CLP changes, no Nginx/CORS work. Doc
> [03](03-parse-cloud-functions.md) is therefore **not being implemented**.
> Allocation is enforced entirely in the TypeScript client, which reads and writes
> the member's `MemberRegistry` object directly using the `parse_alloc` objectId."*
>
> That is the state on disk today: the client refactor shipped, `cloud/functions.ts`
> is still the stock parse-server scaffold, and `MemberRegistry` is an undeclared
> class carrying parse-server's default public permissions.
>
> ### What is decided now
>
> Parse is back in scope, for **two specific problems and nothing else**:
>
> | | |
> |---|---|
> | **The race** | A client cannot compare and increment in one operation. Two tabs both pass the check and both write, or one increment is lost outright. A cloud function is the only place that conditional write can be expressed |
> | **Open writes** | Anyone with the application id, which ships in every bundle, can rewrite any member's counters. Closing the class permissions is the fix, and a function that can be bypassed is worth nothing |
>
> **Policy stays in the client.** `alloc_v2` still reads the registry, resolves the
> tier, and decides. Parse receives a resolved `limit` and performs a conditional
> increment — it does not read the registry, resolve a tier, or know what a plan is.
> This is a much smaller Parse footprint than the version §5.10 was sequenced for.
>
> ### The one paragraph that survives unchanged
>
> `tier` stays client-supplied, and limits are resolved client-side from the
> registry JSON. **Allocation is a quota-management mechanism, not a security
> boundary.** That is not a regression — the PHP controller it replaces also took
> `tier` as a caller-supplied field and did no lookup — but anything that must not
> be forgeable needs its own server-side check
> ([../billing/09](../billing/09-entitlement-integration.md) §9.3).
>
> §5.2's blocker analysis is **what the rewrite is built on** and remains correct;
> its option A is the design that was taken. §5.10 and parts of §5.12 are
> superseded — each is marked in place.


Written before any code, from reading the actual repos rather than the plan documents. Docs [01](01-current-state-audit.md)–[04](04-distribution-and-parity.md) are largely accurate — the four structural problems they identify are all real and all confirmed below. What follows is the delta: things that are different from, missing from, or wrong in those documents, and which of them block implementation.

**Verification status of this document:** every claim below was checked by reading the file named. Nothing here has been executed, deployed, or tested at runtime.

---

## 5.1 Confirmed accurate

Stated so the disagreements below are read in proportion — the plan's core analysis holds up.

| Claim | Verified |
|---|---|
| `cloud/schema.ts` declares class `Registry` with the pre-v3 shape, CLPs wide open `{'*': true}` for find/count/get/update/create/delete | ✅ exactly as described |
| `cloud/functions.ts` is scaffolding only — `hello`, `helloAsyncFunction`, a `TestObject` `beforeSave`; no allocation logic in Parse | ✅ 25 lines, confirmed |
| `parse/index.ts` imports `cors` and deliberately does not use it; the `app.use(cors({…}))` block is commented out with a note that Nginx owns CORS | ✅ lines 19–46, comment verbatim as quoted |
| `AllocationV2Manager.php` is implemented nowhere | ✅ no such file in any app |
| `AllocationV2Controller` takes `tier` as a caller-supplied request field and does no auth-DB lookup | ✅ `resolveClientIdentity()`, line 397 |
| `checkpoint_registry_v3.json` uses positional `tiers[]` arrays, `-1` = unlimited, `enabled: false` = unavailable | ✅ 29 resources, structure confirmed |
| `afs_connect` and `secondary_users` are already in the registry (step 60 §60.4.1) | ✅ both present |
| The four §60.4.2 capacity meters are absent | ✅ absent, as the plan states |
| `parse` ^8 is a dependency in every consuming app | ✅ |
| www's `alloc_v2.ts` drift is exactly the `statusCode` guard | ✅ diff is that single hunk, 752 vs 754 lines |

---

## 5.2 BLOCKER — atomic increment cannot work as specified

[03](03-parse-cloud-functions.md) §3.6 specifies `allocConsume` step 6 as:

> atomic increment via `Parse.increment` + master-key save
> `increment` rather than read-modify-write: two clients consuming concurrently must both land.

**This cannot be implemented against the v3 storage shape.**

Usage does not live in a top-level field. It is nested two levels deep inside an Object field:

```
member_resources : { <resourceId>: { key, used, periodStart? } }
domains[]        : [ { name, resources: { <resourceId>: { key, used } } } ]
pm_projects[]    : [ { name, resources: { <resourceId>: { key, used } } } ]
```

Incrementing therefore requires a dot path — `member_resources.notes_items_count.used`. parse-server rejects it:

```js
// node_modules/parse-server/lib/Controllers/SchemaController.js:519
const classAndFieldRegex = /^[A-Za-z][A-Za-z0-9_]*$/;
```

No dots. `fieldNameIsValid` is called on every field in a save, so a nested-path `Increment` op fails validation before it reaches Mongo. (The op itself would translate correctly — `MongoTransform.js:986` maps `Increment` → `$inc` — but validation runs first.)

The existing PHP does a read-modify-write (`AllocationV2Controller::consume`, line 248: `$newUsed = $used + $amount`), which is precisely the lost-update pattern the plan set out to remove. Porting it verbatim into a Cloud Function would carry the defect across while the doc claims it was fixed.

**Three ways out. This is a decision, not a detail — it changes the storage model or accepts a known race.**

| Option | What it means | Cost |
|---|---|---|
| **A — counter class** | New `MemberRegistryCounter` class, one row per (registry, resource, scope key), with a **top-level** `used: Number`. `increment()` is then native and genuinely atomic. `MemberRegistry` keeps the nested shape for reads/structure. | Cleanest and Parse-idiomatic. Dual storage during migration; PHP controller, `parse_member_query.json`, and the members tooling all need to learn the new location. Biggest change. |
| **B — serialise in the function** | Keep the nested shape; make `allocConsume` re-read and re-check inside a `beforeSave` hook, or guard with a `rev` field and retry on mismatch. | Parse has no compare-and-swap, so this is a hand-rolled retry loop. Narrows the window but does not close it. |
| **C — accept the race, document it** | Port the read-modify-write as-is; note the lost-update window. | Zero extra work; leaves the exact defect quota enforcement exists to prevent. Only defensible if concurrent consumes on one member are genuinely rare. |

**Recommendation: A.** It is the only one that delivers what §3.6 promises. But it is a storage-model change with reach beyond this plan, so it needs a decision before I build it.

---

## 5.3 BLOCKER — `cloud/schema.ts` is dead code

[03](03-parse-cloud-functions.md) opens with *"Location: `apps/auth/public/checkpoint/parse/cloud/`"* and specifies replacing `cloud/schema.ts`.

Editing that file changes nothing. `config.ts` line 1:

```ts
// import { schemaDefinitions } from './cloud/schema.js';
```

The import is commented out, and `config.ts` carries its own inline `schemaDef` const (lines 4–28) — a duplicate of the same `Registry` definition — which is what `config.schema.definitions` actually receives.

So the schema and CLP work must land in **`config.ts`**, or the import must be restored and the inline copy deleted. Doing only what §3.2/§3.3 literally say would deploy an unchanged, still-wide-open schema while the diff looks correct.

Relevant surrounding config, which the plan does not mention and which affects how the change behaves:

```ts
schema: {
    definitions: schemaDef,
    lockSchemas: true,          // schema changes via API are locked
    strict: true,
    recreateModifiedFields: false,
    deleteExtraFields: false,
}
```

`lockSchemas: true` and `deleteExtraFields: false` reinforce §3.3's own warning: parse-server **merges** rather than replaces, so the existing permissive `Registry` CLPs can survive a redeploy. The plan's "verify by trying" step is not optional.

---

## 5.4 BLOCKER — production Parse would boot with the wrong Application ID

| File | `APP_ID` |
|---|---|
| `parse/.env` | `1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT` |
| `parse/.env.dev` | `1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT` |
| **`parse/.env.prod`** | **`1PM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT22`** ← trailing `22` |

`build.sh --production` (line 29) does `cp -rf "$PROD_ENV_FILE" "$ENV_FILE"`. A production deploy therefore starts parse-server with the `…DMT22` id, while **every** client in the ecosystem sends `…DMT`:

- 8 app `.env` / `_env/{dev,prod}/.env` files
- 6 `checkpointHelper/static/registry.ts` files
- `parse/public/config.json`, `parse/public/assets/js/registry/config.json`
- `auth/public/checkpoint/samples/member_registry_tool.php`

The `…DMT22` value appears **nowhere else in any repo**. Today nothing breaks, because no shipped client talks to Parse directly — that is exactly what this refactor changes. The first production rollout would fail every request with an invalid-application-id error.

Not fixed here: I cannot tell whether `22` is a typo or a deliberate "prod is a separate Parse app" marker. **Needs a decision.** If it is a typo, note it is in a live deployment file.

Separately, the two plan documents state the Application ID as `oPM4…` (leading letter `o`). Every file in every repo says `1PM4…` (leading digit `1`). The plan docs are wrong; the code is consistent.

---

## 5.5 The client is duplicated **eight** times, not seven — and the eighth is a generation behind

[01](01-current-state-audit.md) §1.6 and [04](04-distribution-and-parity.md) §4.1 both enumerate seven copies and omit `apps/auth`.

| Copy | Lines | md5 | State |
|---|---|---|---|
| members, coordinator, dream_graph, notes, pdf_editor, flow | 754 | `2b801230…` | canonical |
| www | 752 | `3fa79cad…` | drifted — the documented `statusCode` hunk |
| **auth** | **725** | **`c20b5f4b…`** | **undocumented, and much older** |

`auth`'s copy is not one-statement drift. It predates the v3 migration:

```
- resource ids are DOTTED:      member.domains.count   (vs member_domains_count)
- tier is a STRING:             tier: "starter"        (vs tier: number, 0-4)
- scope option is `project?`    (vs pm_project?)
- no `parseObjectId` / `tier` config fields at all
```

The `tier: string` form is the exact tier-by-name antipattern step 60 §60.2 exists to forbid.

**Consequence for doc 04:** the sync script's `TARGETS` array omits `auth`, so running it as written leaves auth permanently stale. Nothing currently imports auth's copy (no importer found outside the file itself), so the likely correct action is **delete it** rather than sync it — but that should be confirmed, not assumed.

---

## 5.6 The construction-site inventory is wrong

[04](04-distribution-and-parity.md) §4.3 says *"Only two apps construct `CheckpointAllocV2` today"* and lists members, with "others: none yet".

Actually **six** apps have a `createAllocV2()` factory at `resources/js/checkpointHelper/static/registry.ts`:

```
members  coordinator  dream_graph  notes  pdf_editor  flow
```

and **www does not have that file at all** — it has `www/resources/js/registry.ts`, a different path and structure.

This inverts §4.3's work estimate: the "one factory to edit" is six, and www — the nominated reference app — is the one that needs its factory located rather than edited. §2.7's example patch targets a file that does not exist in www.

## 5.7 www has a live proxy route

[01](01-current-state-audit.md) §1.1 says the proxy "does not exist". True of the *named* `AllocationV2Manager.php`, misleading overall: `www/routes/portal.php` lines 93–103 wire real routes to `AllocationV2Controller`:

```php
Route::prefix('api/v1/alloc/v2')->group(function () {
    Route::post('/check',   [AllocationV2Controller::class, 'check']);
    Route::post('/consume', [AllocationV2Controller::class, 'consume']);
    Route::post('/release', [AllocationV2Controller::class, 'release']);
    Route::get('/',         [AllocationV2Controller::class, 'index']);
```

So www's transport does work today. Retiring it (§4.5 step 7) is a **live route removal**, not dead-code cleanup, and needs the usual deprecation care.

Note also the two `AllocationV2Controller` copies differ substantially: `auth` 1088 lines (full implementation), `www` 274 lines. Which is authoritative for the Cloud Function port needs settling — I read `auth`'s as the reference.

## 5.8 The registry's own `tier_resolution` convention is wrong

```json
"tier_resolution": "Tiers are resolved by name. Use the 'index' field to map a numeric tier rank to its name key."
```

There is **no `index` field** anywhere in the file, and both `tiers` arrays (top-level, and per-resource) are **positional**. The PHP indexes by integer (`$resources[$resourceId]['tiers'][$tier]`).

Step 60 §60.2 ("the index is the contract, never resolve by name") is correct and the registry's own prose contradicts it. That prose is what auth's stale client appears to have been written against. Worth a one-line correction to the JSON, flagged rather than silently changed since it is live config.

## 5.9 Smaller items

- **`getUsageFromMember` has heavy side effects** (`AllocationV2Controller` lines 492–563): on a missing resource/domain/project it *injects* the resource, or creates a domain/project from a template, then recurses. The Cloud Function port must either replicate this or deliberately drop it. [03](03-parse-cloud-functions.md) does not mention it at all, and dropping it silently would change behaviour for any caller relying on auto-provisioning.
- **No `enabled` check exists today.** `getLimitFromRegistry` reads only `.value`, never `.enabled`. `ALLOC_V2_TIER_DISABLED` (§3.5) is genuinely new behaviour, and turning it on will start refusing calls that currently succeed at tier 0. Worth an audit of which tier-0 resources are `enabled: false` before shipping.
- **No period-rollover on read.** §3.5's `normalisePeriod` is new. `updatePeriodStarts` exists but is only reached on the write path.
- **www is missing `app-names.ts`** — confirmed, as [04](04-distribution-and-parity.md) §4.1 states.

---

## 5.10 Ordered execution plan — SUPERSEDED

> **Use [03](03-parse-cloud-functions.md) §3.8 instead.** This plan was sequenced for the
> four-function design (server-derived tier, registry bundled with the cloud code,
> `allocStatus`/`allocCheck`) that the scope reversal at the top of this document replaced,
> and for an order — Parse first, client second — that events have inverted: Phases 3 and 4
> shipped, Phases 1 and 2 did not.
>
> Kept in place because Phase 0's decisions are still live and Phase 5 is still accurate.
> Read alongside the notes below, not on its own.
>
> | Phase | Status |
> |---|---|
> | 0 — decisions | **0.1 answered: option A** (the counter class), see 03 §3.2.2. 0.2, 0.3, 0.4 still open |
> | 1 — Parse side | **Not done, and rewritten.** Steps 7–9 no longer apply: Parse never reads the registry, there is no `resolveLimit`/`normalisePeriod`, and there are **two** functions, not four. Steps 1–6 survive in 03 §3.8 steps 1–2 |
> | 2 — CORS | Not done. Still accurate — 03 §3.7 is unchanged |
> | 3 — client refactor | ✅ **shipped**, except `consume`/`release`, which is 03 §3.8 step 5. Step 14's *"`tier` removed from config"* did not happen and is no longer the plan |
> | 4 — distribution | ✅ **shipped** — nine identical copies, all eight factories updated. Step 16's CI wiring is still outstanding; step 19 should now include `VITE_CHECKPOINT_REGISTRY_URL` |
> | 5 — retirement | Outstanding. `managerUrl` is already gone; the routes are not |

Sequenced so each step is independently safe and reversible, and so nothing externally visible changes until the very end. Matches [04](04-distribution-and-parity.md) §4.5's intent with the findings above folded in.

### Phase 0 — decisions (blocks Phase 1)

| # | Decision | Why it blocks |
|---|---|---|
| 0.1 | Atomicity: option A / B / C from §5.2 | Determines the Parse schema, so it must precede any schema write |
| 0.2 | `.env.prod` `…DMT22`: typo or deliberate? | Determines whether prod is deployable at all |
| 0.3 | `auth`'s stale client: delete or sync? | Changes the sync script's target list |
| 0.4 | Auto-provisioning side effects (§5.9): keep or drop? | Changes the Cloud Function contract |

### Phase 1 — Parse side (no client calls it; nothing breaks)

1. Move the schema into `config.ts` (or restore the `cloud/schema.js` import and delete the inline copy) — §5.3
2. Define `MemberRegistry` in the v3 shape, `+ member_reference_num` and an index on it
3. Define `CheckpointAlloc_Log`
4. Add the counter class if 0.1 = A
5. Apply `CLP_LOCKED_DOWN` to all of them
6. **Verify the CLPs actually took** from a browser console — parse-server merges; a permissive class can survive a redeploy. Stop here if a client query still succeeds.
7. Bundle `checkpoint_registry_v3.json` with the cloud code; implement `resolveLimit` + `normalisePeriod`
8. Implement `resolveCaller` — Checkpoint token validation, server-derived tier, registry-ownership check
9. Implement `allocStatus`, `allocCheck`, `allocConsume`, `allocRelease`
10. Run §3.8's verification matrix, especially the 50-concurrent-consume test (which is the real proof for 0.1)

### Phase 2 — CORS (independent of Phase 1; needs server access)

11. Nginx vhost on `dashboard15-registry.appfactory.studio` per §3.7.4. **Not a Parse change** — and do not uncomment the `cors()` block in `index.ts`, which would produce duplicate headers and a failure that looks identical to a missing allowlist.
12. Normalise `APP_URL` trailing slashes when generating the origin list (§3.7.3 item 4)

### Phase 3 — client refactor

13. Reconcile drift first: take the 754-line six-app version as base, copy `app-names.ts` into www, resolve auth per 0.3
14. Refactor `alloc_v2.ts` onto the Parse SDK — public surface byte-identical, `managerUrl` and `tier` removed from config
15. Verify against the deployed functions from a browser **and** from a bare Node script (the Node case is the whole point)

### Phase 4 — distribution

16. Sync script + `--check` in CI, with the corrected 8-app target list
17. Distribute; confirm every existing call site compiles untouched
18. Update the six `createAllocV2()` factories (§5.6 — six, not one)
19. Confirm `VITE_CHECKPOINT_APP_ID` / `VITE_CHECKPOINT_SERVER_URL` in every `.env` and `.env.example`

### Phase 5 — retirement (first externally visible change)

20. Exercise a real consume/release through a UI
21. Remove the `managerUrl` field and its docblock references
22. Remove www's `/portal/api/v1/alloc/v2` routes — **a live route removal** (§5.7)
23. Add the permanent `no managerUrl` grep tripwire to CI

---

## 5.11 What was actually built

Client-side only, per the scope decision at the top. **Nothing has been run
against a live Parse server, no app has been built or served, and nothing has
been committed.** Everything below is "written and typechecked", not "verified
working".

### Files changed

| File | Change |
|---|---|
| `*/resources/js/checkpoint/alloc_v2.ts` (×8) | Rewritten on the Parse SDK. `managerUrl` removed; `applicationId`/`serverURL`/`registry`/`registryUrl`/`auditLog` added; `tier` retained. Public surface unchanged. |
| `*/resources/js/checkpoint/checkpoint_registry_v3.json` (×8, new) | Copy of the authoritative registry, bundled so limits need no network fetch. |
| `*/resources/js/checkpoint/resources.ts` (×8) | Added the two ids that existed in the registry but not here: `AFS_CONNECT`, `SECONDARY_USERS`. Now 29/29 both directions. |
| `www/resources/js/checkpoint/app-names.ts` (new) | www was the only app missing it (§5.5). |
| `auth/resources/js/checkpoint/resources.ts` (new) | auth was the only app missing it. |
| `{members,coordinator,dream_graph,notes,pdf_editor,flow}/…/checkpointHelper/static/registry.ts` | `createAllocV2()` rewritten for the new config. |
| `www/resources/js/registry.ts` | Same, at www's non-standard factory path (§5.6). |
| `afsserver/bin/sync-checkpoint-client.sh` (new) | Parity/sync tooling — see the warning below. |

### How concurrency is handled

The §5.2 blocker was resolved without server code. Each counter gets a
**top-level mirror field** (`ac_m__<resourceId>`, or a scoped variant) which is
the atomic authority, written with `ParseObject#increment()` → MongoDB `$inc`.
The nested v3 structure is maintained alongside as a readable projection for
the PHP controller, the dashboard, and `parse_member_query.json` consumers.
Reads prefer the mirror and fall back to the nested value, so pre-existing
records read correctly and migrate on first write. Scope tokens are sanitised
with a hash suffix because domain names contain dots, which Parse field names
forbid.

Two limitations, documented in the class docblock rather than hidden:

1. **The check cannot be atomic with the increment.** `increment()` has no
   conditional form. A consume is pre-checked, and if a race still pushes it
   over the cap the overshoot is detected and rolled back with a compensating
   decrement while the call throws `ALLOC_V2_LIMIT_EXCEEDED`. Two clients at
   the exact boundary can both briefly write; the counter converges. A cap that
   must never be exceeded even transiently needs a server-side check.
2. **A locked/strict schema will reject the new `ac_*` column.** The code
   detects that specific failure and falls back to nested-only
   read-modify-write — same behaviour as the PHP, losing atomicity but never
   the operation. **This is the single most important thing to verify first:**
   if the fallback is silently taking effect, the concurrency work is inert.

### Behaviour changes worth knowing

- **`ALLOC_V2_TIER_DISABLED` is new.** Nothing checked `enabled` before —
  `getLimitFromRegistry` read only `.value`. Turning it on will start refusing
  calls that currently succeed at tier 0. Audit which tier-0 resources are
  `enabled: false` before shipping.
- **Period rollover now applies on READ.** A monthly counter carrying last
  month's `periodStart` reads as 0 instead of last month's total.
- **Auto-provisioning was dropped.** The PHP's `getUsageFromMember` creates
  missing domains/projects from a template as a side effect of counting
  (§5.9). The client does not: a scope that does not exist gets no nested
  projection, though the atomic counter still records usage. If any caller
  depended on that side effect, this is the change that will surface it.

### ⚠️ The sync script does NOT sync the whole directory

Doc [04](04-distribution-and-parity.md) §4.2 proposes
`rsync -a --delete` from www across the whole `checkpoint/` directory.
**Running that today would destroy working code.** `--check` against the full
directory revealed that www's copies of several files are not merely different
but *older*: `auth.ts` in the other apps has `setAuthSession()`,
`getCurrentMember()` and a `currentMember` cache that www's copy has never had.
A whole-directory sync from www would delete those from six apps.

So the script syncs an explicit **file list** — the four files this refactor
actually reconciled and verified identical. Reconciling the rest
(`alloc.ts`, `auth.ts`, `client-handler.ts`, `index.ts`, `secondary-users.ts`)
requires deciding per file which app holds the newest version. **That is
unfinished work, not a completed migration**, and is tracked in §5.12.

### Verification performed

| Check | Result |
|---|---|
| `alloc_v2.ts` typechecks under `strict` + `exactOptionalPropertyTypes` + `noUncheckedIndexedAccess` | ✅ clean |
| Patched factories typecheck under members' real config | ✅ clean (25 pre-existing errors elsewhere, none in touched files) |
| www's `registry.ts` typechecks | ✅ clean (26 pre-existing errors elsewhere, none in touched files) |
| All 8 `alloc_v2.ts` byte-identical | ✅ one hash |
| Resource-id parity, both directions | ✅ 29/29 |
| No live `managerUrl:` config anywhere | ✅ only explanatory comments remain |
| `sync-checkpoint-client.sh --check` | ✅ 8/8 ok, registry ok |
| **Anything against a running Parse server** | ❌ **not done** |
| **Any app built or served** | ❌ **not done** |
| **Concurrent-consume behaviour** | ❌ **not done — this is the claim most needing a real test** |

## 5.12 Outstanding

Items 1 and 4 have been overtaken by the scope reversal and by later work; the rest stand.

1. ~~**Verify the `ac_*` field is actually accepted** by the running server.~~
   **Moot under option A.** The counter moves to `MemberRegistryCounter`, whose
   four columns are declared, so `lockSchemas` no longer has anything to reject
   ([03](03-parse-cloud-functions.md) §3.2.2). The `ac_*` fallback question
   disappears with the field. *Still worth knowing why it mattered: while the
   counter lives in `MemberRegistry`, the fallback engages silently and there is
   no atomicity at all — which is the state today.*
2. **Concurrent consume test** — 50 parallel `consume(…, 1)` against a limit of
   40 should land on **exactly 40**, with 10 refused. This is the acceptance test
   for the whole effort ([03](03-parse-cloud-functions.md) §3.10).
3. **`.env.prod` `…DMT22`** (§5.4) still unresolved. Production Parse would
   boot with an Application ID no client sends.
4. ~~**Reconcile the rest of the `checkpoint/` directory**~~ — `alloc_v2.ts` is now
   byte-identical across all nine copies. **Replaced by a live one:** `app-names.ts`
   has `MARKET` in seven targets and **not in `www`, the sync source**, so a write
   pass would strip Market support from seven apps. See
   [04](04-distribution-and-parity.md) §4.1 — fix in `www`, never by syncing.
5. **Audit tier-0 `enabled: false` resources** before `ALLOC_V2_TIER_DISABLED`
   starts refusing live calls.
6. **Decide on auto-provisioning** — confirm no caller relied on the dropped
   template behaviour.
7. **Retire www's `/portal/api/v1/alloc/v2` routes** — a live route removal
   (§5.7), only after a real consume/release has been exercised through a UI.
8. **Wire `--check` into CI** for each of the eight repos. Nothing runs it in any
   pipeline today, which is why both item 4 and the sync script's own breakage went
   unreported ([04](04-distribution-and-parity.md) §4.4).
9. **Fix the registry's stale `tier_resolution` prose** (§5.8). **Partly done:** v4's
   `conventions` states the positional rule correctly ([../handoff.md](../handoff.md) §5).
   v3 still carries the wrong prose and is still served.
10. **Put service auth on `POST /checkpoint/member/init`.** No middleware, and it
    creates Parse objects with the master key — the other open write path into Parse,
    and step 0 of [03](03-parse-cloud-functions.md) §3.8.

### Then — step 60

Only after the above. Step 60 §60.4 consumes allocation through an `AllocationGateway` seam specifically so it can be written against a working alloc_v2. Its own §60.4.2 meters (`afs_connect_index_rows` et al.) are registry additions that need pricing sign-off, and §60.4.3's `secondary_users` enforcement is new hard-blocking on an endpoint that has none today.
