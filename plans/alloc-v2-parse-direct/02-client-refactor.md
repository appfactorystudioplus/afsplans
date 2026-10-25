# 02 — Client Refactor: `CheckpointAllocV2` on the Parse SDK

> ### ⚠ Status — 2026-08-31
>
> **The transport half of this document has shipped. The mutation half has not.**
>
> | | State |
> |---|---|
> | `managerUrl` removed, Parse SDK direct | ✅ all nine copies |
> | `getAll` / `get` / `canUse` reading Parse | ✅ shipped — but they read `MemberResource` rows now, not a per-member object |
> | §2.5 `consume` / `release` via Cloud Functions | ❌ **not built.** They still `increment()` and `save()` the row from the browser |
>
> Two constructor changes since this was written, both in all nine copies:
>
> - **`parseObjectId` is gone.** The `MemberRegistry` anchor it addressed was deleted on
>   2026-08-31 (`../00_ISSUES.md` **I7**).
> - **`memberReferenceNum` is required**, and is the only member identity the client takes.
>
> §2.5's shape is still the target. The payload changes to
> `{ resource_id, scope_type, scope_key, scope_parent, amount, limit }` — see
> [03](03-parse-cloud-functions.md) §3.4 — and the client's own compensating-decrement logic
> comes out when the function goes in, or both layers will refund the same overshoot twice.

**Rule for this document:** every public method keeps its exact name, parameter list, return type and thrown error type. Only the transport changes. A consumer that compiles today must compile after, with no edit beyond the constructor call.

Reference file: `apps/www/resources/js/checkpoint/alloc_v2.ts` — but see [01](01-current-state-audit.md) §1.6: base the work on the six-app version, which is the one without the drift.

> ### ✅ Shipped, and amended in two places — verified 2026-08-27
>
> **The transport change in this document is done.** `managerUrl` is gone from all nine
> copies of `alloc_v2.ts`; the client calls `Parse.initialize()` and reads
> `MemberRegistry` directly. The file's own header now opens *"`managerUrl` is gone"*.
>
> Two things it describes are no longer the plan, both because
> [03](03-parse-cloud-functions.md) was rewritten to leave policy in the client:
>
> 1. **`tier` was not removed and is not derived server-side.** It stays in the config and
>    is still caller-supplied, so allocation remains advisory
>    ([../allocation.md](../allocation.md) §7). Parse now receives a `limit` from the client
>    instead of resolving one itself — [03](03-parse-cloud-functions.md) §3.5 for the split,
>    §3.4.3 for what that leaves open.
> 2. **`allocCheck` and `allocStatus` do not exist.** There are two functions, not four
>    ([03](03-parse-cloud-functions.md) §3.4). `canUse()` stays a client-side read against
>    the member object; `getAll()`/`get()` likewise.
>
> **What has not shipped is the mutation half.** `consume` and `release` still write
> `MemberRegistry` directly — `object.increment()` then `object.save()`, falling back to a
> whole-object read-modify-write when the flat counter column is rejected by the locked
> schema. Moving those two methods onto `Parse.Cloud.run` is
> [03](03-parse-cloud-functions.md) §3.8 step 4, and it is the remaining work in this
> document.
>
> One consequence worth stating: doc 03's own preamble warned that shipping this document
> without 03 *"would convert a missing feature into a data-integrity hole."* That is the
> state the estate is in right now — every client writes counters directly, against a class
> whose permissions are public. Step 4 and step 5 of §3.8 are what close it.

---

## 2.1 What changes, precisely

| | Before | After |
|---|---|---|
| Transport | `fetch()` → `AllocationV2Manager.php` → auth → Parse | Parse JS SDK → `https://dashboard15-registry.appfactory.studio/parse` |
| Config | `managerUrl`, `getToken`, `parseObjectId`, `tier`, `timeout`, `cacheTtl` | `applicationId`, `serverURL`, `parseObjectId`, `getToken`, `javascriptKey?`, `timeout?`, `cacheTtl?` |
| `tier` | caller-supplied | **stays in config, still caller-supplied** — see the amendment below |
| Reads | POST to proxy | `Parse.Query.get(parseObjectId)` |
| Mutations | POST to proxy | `Parse.Cloud.run('allocConsume' \| 'allocRelease', …)` |
| Errors | `AllocV2Error(message, code, statusCode?)` | **unchanged** — Parse errors are translated into the same shape |

**Unchanged public surface:** `getAll`, `get`, `canUse`, `consume`, `release`, `getRemaining`, `getLimit`, `getUsage`, `isUnlimited`, `invalidateCache`, and the exported types `AllocV2Scope`, `AllocV2Metric`, `AllocV2Resource`, `AllocV2Status`, `AllocV2CheckResult`, `AllocV2MutationResult`, `AllocV2MutationOptions`, `AllocV2Error`.

`AllocV2Response<T>` describes the proxy's envelope and has no meaning against Parse. Keep the export (removing it is a breaking change for anything that imported the type) but mark it `@deprecated` with a note that nothing produces it any more.

## 2.2 Configuration

```ts
/**
 * Configuration object for {@link CheckpointAllocV2}.
 *
 * TRANSPORT CHANGE (see plans/alloc-v2-parse-direct/): this client now speaks
 * to the Parse registry server DIRECTLY. The former `managerUrl` field — which
 * pointed at an `AllocationV2Manager.php` proxy on the calling application's
 * own origin — has been removed. It made alloc_v2 unusable from any client
 * that is not a Laravel web app (desktop, mobile, CLI), because such a client
 * has no same-origin PHP endpoint and no way to discover which app server to
 * address. It also named a proxy that was never implemented in any app.
 */
export interface AllocV2Config {
    /**
     * Parse Application ID — the first argument to `Parse.initialize()`.
     *
     * Not a secret: Parse Application IDs are designed to be shipped in
     * clients. Authorisation is enforced by class-level permissions and Cloud
     * Function checks on the server (see 03-parse-cloud-functions.md), never by
     * withholding this value. The Parse MASTER key is the secret, and it never
     * leaves the Parse server.
     *
     * @example 'oPM4yMxbQ0ihyNObm4uQAsG4Zkpl7ZLH5lyquDMT'
     */
    applicationId: string;

    /**
     * Parse Server URL — assigned to `Parse.serverURL`.
     *
     * ALWAYS an absolute URL. This is the single change that makes alloc_v2
     * server-independent: every client, on every platform, addresses the same
     * registry host rather than a per-application relative path.
     *
     * @example 'https://dashboard15-registry.appfactory.studio/parse'
     */
    serverURL: string;

    /**
     * Parse JavaScript Key, when the deployment requires one.
     *
     * Optional: parse-server only enforces it if `javascriptKey` is configured
     * server-side. Passing a wrong value when the server does not require one
     * is harmless; omitting it when the server DOES require one produces
     * `ALLOC_V2_UNAUTHORIZED` on the first call, which is the symptom to look
     * for if every request fails identically on a fresh deployment.
     */
    javascriptKey?: string;

    /**
     * Parse objectId of this member's `MemberRegistry` record.
     * Found at `app.window.member.parse_alloc`.
     *
     * Used directly for reads. Mutations do not send it at all — the Cloud
     * Function resolves the member from the Checkpoint token and ignores any
     * member the payload names, so an objectId is never a capability
     * (03-parse-cloud-functions.md §3.4.2).
     */
    parseObjectId: string;

    /**
     * Returns the current Checkpoint Bearer JWT, or null/undefined when the
     * user is not authenticated. May be sync or async.
     *
     * The token is forwarded to every Cloud Function so the server can resolve
     * WHO is calling and therefore which tier applies. This is why `tier` is no
     * longer a config field: a value supplied by the client is worth nothing
     * once the client talks to the server directly.
     */
    getToken: () => string | null | undefined | Promise<string | null | undefined>;

    /** Request timeout in milliseconds. Defaults to 30_000. */
    timeout?: number;

    /**
     * How long (ms) to cache the full status from {@link CheckpointAllocV2#getAll}.
     * Defaults to 30_000. Bypass with `forceRefresh` or {@link invalidateCache}.
     */
    cacheTtl?: number;
}
```

## 2.3 Initialisation — once per process, not once per instance

```ts
/**
 * Parse's JS SDK keeps ONE global configuration per process
 * (`Parse.initialize` / `Parse.serverURL` are module-level state, not
 * per-instance). Constructing two CheckpointAllocV2 objects with different
 * `applicationId`s in the same page is therefore not supported by Parse
 * itself — the second wins for both.
 *
 * This guard makes that explicit instead of silently mis-routing: the first
 * initialisation is applied, and a later one with DIFFERENT values throws
 * rather than quietly repointing every existing instance at another server.
 * Re-initialising with identical values is a no-op, so multiple instances
 * sharing one configuration (the normal case) work fine.
 *
 * Callers embedding this in a host that already initialises Parse (a desktop
 * shell, another SDK) should pass the same values that host used.
 */
let parseInitState: { applicationId: string; serverURL: string } | null = null;

function ensureParseInitialised(cfg: Required<Pick<AllocV2Config, 'applicationId' | 'serverURL'>>
                                    & Pick<AllocV2Config, 'javascriptKey'>): void {
    if (parseInitState) {
        if (parseInitState.applicationId !== cfg.applicationId ||
            parseInitState.serverURL     !== cfg.serverURL) {
            throw new AllocV2Error(
                'Parse is already initialised with a different applicationId/serverURL. ' +
                'The Parse SDK holds one global configuration per process.',
                'ALLOC_V2_PARSE_ALREADY_INITIALISED',
            );
        }
        return;
    }

    Parse.initialize(cfg.applicationId, cfg.javascriptKey);
    Parse.serverURL = cfg.serverURL;
    parseInitState = { applicationId: cfg.applicationId, serverURL: cfg.serverURL };
}
```

## 2.4 Reads — `getAll` and the derived accessors

```ts
/** Parse class holding one allocation record per member. */
const MEMBER_REGISTRY_CLASS = 'MemberRegistry';

/**
 * Fetch the member's full allocation status.
 *
 * Reads the MemberRegistry object directly (a plain Parse query — no Cloud
 * Function needed, because reading your own record is not a privileged
 * operation once CLPs restrict `get` to the owning session) and merges it with
 * the tier limits the server reports.
 *
 * Shape note: the stored object uses the v3 layout — `member_resources` keyed
 * by resource id, plus `domains[]` and `pm_projects[]` arrays whose entries
 * carry their own `resources` maps (see parse_member_query.json). The two
 * older shapes described in 01-current-state-audit.md §1.2 are NOT read.
 *
 * @param forceRefresh Bypass the {@link AllocV2Config.cacheTtl} cache.
 * @throws AllocV2Error on network failure, an unknown objectId, or a rejected
 *         identity — with the same error codes the proxy transport used, so
 *         existing catch blocks keep working.
 */
async getAll(forceRefresh = false): Promise<AllocV2Status> {
    if (!forceRefresh && this.cachedStatus && Date.now() - this.lastFetch < this.config.cacheTtl) {
        return this.cachedStatus;
    }

    // A plain read of the member's own registry object. Limits are resolved
    // client-side against the registry file this client already fetches, and
    // merged with the stored usage here.
    //
    // NOT a Cloud Function: reads stay direct (03 §3.3 keeps `get` open on
    // MemberRegistry). Only mutations route through cloud code, because only
    // mutations have a race to close.
    const object = await new Parse.Query(MEMBER_REGISTRY_CLASS).get(this.config.parseObjectId);
    const status = this.mergeUsageWithLimits(object);

    this.cachedStatus = status;
    this.lastFetch = Date.now();
    return status;
}
```

`get`, `getRemaining`, `getLimit`, `getUsage` and `isUnlimited` are unchanged: they already derive from `getAll()` and the scope arguments, so they need no edit at all beyond what `getAll` now returns.

## 2.5 Mutations — Cloud Functions, not object writes

```ts
/**
 * Record consumption of `amount` units of `resourceId`.
 *
 * Routed through the `allocConsume` Cloud Function rather than a client-side
 * `increment()` + `save()` for two reasons, both of which would be defects if
 * ignored:
 *
 *   1. THE RACE — the compare and the increment must happen in ONE operation.
 *      From here they cannot: this client reads, compares, and writes in two
 *      round trips, so two tabs both pass the check and both write. The Cloud
 *      Function increments only if the result stays within `limit`, which is
 *      the one thing the SDK offers no way to express (03 §3.1.1).
 *
 *   2. NOBODY ELSE WRITES — CLPs deny direct writes to MemberRegistry and to
 *      the counter class (03 §3.3), so this method is the ONLY write path.
 *      Without that, a caller could simply skip the function.
 *
 * `limit` is resolved HERE, from the registry and the member's tier, and
 * passed down. Parse does not read the registry and does not know what a plan
 * is (03 §3.5). That means a hand-crafted call can pass a false limit —
 * allocation stays advisory, exactly as ../allocation.md §7 already states.
 *
 * Signature, options and thrown errors are unchanged from the proxy version.
 * `ALLOC_V2_LIMIT_EXCEEDED` is still what a `block` resource throws when the
 * request would exceed the tier limit — now raised from the function's
 * `{ ok: false }` rather than decided locally.
 */
async consume(
    resourceId: string,
    amount = 1,
    options?: AllocV2MutationOptions,
): Promise<AllocV2MutationResult> {
    const result = await this.run<AllocV2MutationResult>('allocConsume', {
        resourceId,
        amount,
        limit:      this.resolveLimit(resourceId),   // from the registry, this client
        domain:     options?.domain,
        pm_project: options?.pm_project,
        context:    options?.context,
    });

    // Any mutation invalidates the cached status — the next getAll() must not
    // serve a stale `used` that this very call just changed.
    this.invalidateCache();
    return result;
}
```

`release` is identical against `allocRelease`, minus `limit` — a release has no ceiling to test, only a floor of zero, which the function clamps.

**`canUse` calls nothing.** It stays a local read: `getAll()` for usage, the registry for the ceiling, compared here. It gates the UI and is advisory by construction — a `canUse` that passes followed by a `consume` that refuses is **correct**, not a bug, because someone else consumed in between. The UI has to be able to render that outcome ([03](03-parse-cloud-functions.md) §3.5).

## 2.6 One place that talks to Parse

```ts
/**
 * The single Cloud Function invocation point — the direct replacement for the
 * old private `request()` helper, and the only method in this class that
 * touches Parse.
 *
 * Responsibilities:
 *   - attach identity (the Checkpoint JWT) and the member's registry objectId
 *     to every call, so no individual method has to remember to;
 *   - enforce the configured timeout, which Parse.Cloud.run does not offer;
 *   - translate every Parse failure mode into AllocV2Error with the SAME codes
 *     the proxy transport produced, so existing catch blocks are unaffected.
 *
 * Error mapping (Parse code → AllocV2Error code):
 *   141 (SCRIPT_FAILED)   → the function's own thrown code, e.g.
 *                           ALLOC_V2_LIMIT_EXCEEDED / ALLOC_V2_TIER_DISABLED
 *   101 (OBJECT_NOT_FOUND)→ ALLOC_V2_REGISTRY_NOT_FOUND
 *   209 (INVALID_SESSION) → ALLOC_V2_UNAUTHORIZED
 *   100 (CONNECTION_FAILED) / timeout → ALLOC_V2_NETWORK_ERROR
 *   anything else         → ALLOC_V2_UNKNOWN_ERROR
 */
private async run<T>(fn: string, params: Record<string, unknown>): Promise<T> {
    const token = await this.config.getToken();
    if (!token) {
        throw new AllocV2Error('No Checkpoint access token available.', 'ALLOC_V2_UNAUTHORIZED');
    }

    const payload = {
        ...stripUndefined(params),
        parseObjectId: this.config.parseObjectId,
        checkpointToken: token,
    };

    try {
        return await withTimeout(
            Parse.Cloud.run(fn, payload) as Promise<T>,
            this.config.timeout,
        );
    } catch (err) {
        throw toAllocV2Error(err);
    }
}
```

`stripUndefined` matters more than it looks: Parse serialises `undefined` object properties into the request, and a Cloud Function receiving `domain: undefined` cannot distinguish "member-scoped" from "malformed". Strip them at the boundary.

## 2.7 Call-site migration

Only construction changes. Members' `registry.ts` factory:

```ts
/**
 * AllocV2 factory.
 *
 * WAS: `managerUrl: '/portal/api/v1/alloc/v2'` — a relative path to a PHP
 * proxy that only resolves inside this Laravel app (and was never written).
 * NOW: the Parse registry host, identical for every client on every platform.
 *
 * `tier` IS still passed. It is what this client resolves limits against, and
 * it is still caller-supplied — so allocation remains advisory quota
 * management, not a security boundary (../allocation.md §7). Parse receives a
 * resolved `limit` on mutations and does not derive one of its own
 * (03 §3.5); the server-derived answer, for anything that must not be
 * forgeable, is ../billing/09-entitlement-integration.md §9.3.
 */
export function createAllocV2(_app: ApplicationContextManager): CheckpointAllocV2 {
    return new CheckpointAllocV2({
        applicationId: import.meta.env.VITE_CHECKPOINT_APP_ID,
        serverURL:     import.meta.env.VITE_CHECKPOINT_SERVER_URL,
        parseObjectId: app.window.member?.parse_alloc ?? '',
        getToken: () => {
            const match = document.cookie.match(/(?:^|;\s*)portal_auth_token=([^;]+)/);
            return match?.[1]
                ?? sessionStorage.getItem('portal_auth_token')
                ?? localStorage.getItem('portal_auth_token')
                ?? null;
        },
    });
}
```

`VITE_CHECKPOINT_APP_ID` and `VITE_CHECKPOINT_SERVER_URL` are **already wired** in each app's `checkpointHelper/static/globalAuth.ts` and passed to `CheckpointClient`. Reuse them rather than introducing a second pair of variables — one Parse configuration per app, read from one place.

## 2.8 Verification

| Check | Expect |
|---|---|
| `getAll()` from a browser | one Cloud Function round trip to the registry host; no request to any app origin |
| `getAll()` from a Node script with no DOM | works — the point of the refactor |
| Every existing call site | compiles unchanged; only the two factories were edited |
| `consume()` past the tier limit | throws `AllocV2Error` with `ALLOC_V2_LIMIT_EXCEEDED`, exactly as before |
| Two tabs consuming concurrently | both increments land; no lost update |
| Direct `new Parse.Object('MemberRegistry')` write from a client | **denied by CLPs** ([03](03-parse-cloud-functions.md) §3.3) |
| A client passing another member's `parseObjectId` | rejected server-side, not served |
| Expired Checkpoint token | `ALLOC_V2_UNAUTHORIZED`, not a silent success |
| Registry host unreachable | `ALLOC_V2_NETWORK_ERROR` after the configured timeout, not a hang |
| Second `CheckpointAllocV2` with a different `applicationId` | throws `ALLOC_V2_PARSE_ALREADY_INITIALISED` rather than repointing the first |
