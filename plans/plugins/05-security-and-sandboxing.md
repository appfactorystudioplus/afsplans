# 05 — Security and sandboxing

## Completion document

When this plan step is completed, write `complete/05-security-and-sandboxing.complete.md`.

## Code documentation

Document all new and changed code thoroughly. Every security check names the threat it defends
against — this document is the source of truth for what those threats are, so a check that
doesn't cite a section here is a check whose purpose has not been thought through.

---

> **Revised 2026-09-27**, in two passes, following a security review of this document against 02,
> 03, 04, 07, 08, 09, 10 and 13.
>
> **First pass** — §10 (new) binds every capability-SDK callback to a Core-Platform-issued
> invocation credential rather than trusting what the Plugin Runtime process asserts about itself;
> §11 (new) gives the release-signing key — on which D6 and every other signature check in this
> document depends — an actual custody, rotation, and compromise-response plan. §1–§4 gained short
> cross-references to both.
>
> **Second pass** — §4 and §6 (this document) close the SSRF gap in the `http.egress` allowlist
> (§6.1, new) and resolve which party actually names the allowlisted hosts (the manifest declares
> them, per [04 §3](04-manifest-and-package-format.md), the tenant approves the declared list
> verbatim — never types one in). The corresponding frontend gaps — an incomplete iframe CSP and
> no origin/source check on the postMessage relay — are fixed in
> [08 §3–§4](08-frontend-runtime-and-dynamic-loading.md), and the anti-sybil gap on developer
> suspension is fixed in [03 §1](03-database-schema.md) and
> [10 §2, §6](10-review-and-publishing-pipeline.md).
>
> **Third pass** — §11 gains two things it was missing: signing is now gated on an actual
> reviewer-issued approval assertion, not merely on the calling pipeline application being
> authenticated (closing a review-bypass path that never touched the key itself), and every
> release now records `signing_key_id` ([03 §1](03-database-schema.md)) so verification and any
> future compromise response can identify which key signed what — which also lets §11's routine
> rotation policy be corrected: old keys stay valid for verification indefinitely instead of
> requiring the entire historical catalog to be re-signed on every scheduled rotation. §10 gains
> a new §10.1 authenticating and encrypting the **dispatch** direction (Core Platform → Plugin
> Runtime), which had been left asymmetric against the callback direction §10 already covered.
> Propagated to [04 §5](04-manifest-and-package-format.md) and
> [10 §4–§5](10-review-and-publishing-pipeline.md).
>
> **Fourth pass** — building [16](16-example-plugin-cookie-consent.md), the first real plugin,
> surfaced two gaps: no capability existed for an efficient counter (§4 gains
> `plugin_data.increment`, the deliberate alternative to giving plugins their own database engine),
> and nothing covered an invocation starting from an anonymous site visitor rather than an
> authenticated admin or a backend hook (§12, new, with a matching catalog flag,
> `reachable_from_public_site`, in [03 §1](03-database-schema.md)).
>
> **Fifth pass** — walking through how the cookie-consent plugin actually renders its banner text
> surfaced that §12's public path was write-only with no way to read even a plugin's own,
> intentionally public settings. §12 and §4 (this document) narrow, rather than reverse, that
> restriction: `plugin_data.read` is reachable from a public invocation only for a collection the
> manifest declared `public: true` ([04 §4](04-manifest-and-package-format.md)) — never a general
> public-read grant. §12 also now states why the public path calls its own endpoint directly
> instead of relaying through a trusted parent the way the admin iframe does (08 §4) — there is no
> equivalent trusted parent on a public page, and nothing sensitive to protect that way.
>
> **Sixth pass — corrected 2026-09-27, a bigger fix than the previous five.** Every prior pass
> above still assumed one shared application instance serving many logical tenants, distinguished
> by a `tenant_reference_num` — wrong; this is not a multi-tenant SaaS app ([00 §0](00-overview.md)).
> A tenant is an independently deployed, independently databased instance of this codebase; there
> is exactly one tenant inside any deployment's own Plugin Runtime, ever. This pass removes
> `tenant_reference_num` from the invocation token (§2, §3, §10) and from every "cross-tenant"
> framing in the threat model (§1) and capability table (§4) — the isolation those cited was never
> reachable in the first place, since nothing in one deployment can address another's data at all.
> What those checks actually needed to defend against — one **install** (plugin) impersonating
> another, within the *same* deployment's Plugin Runtime — is what §1/§4/§10 now say. §12 is
> simplified the most: "resolve the tenant from the request's domain" is gone entirely, since a
> deployment already and only ever answers for itself; identifying *which plugin's* public endpoint
> was called is a local lookup by the slug already in the URL path, nothing more.

## 0. Why this document is the plan's center of gravity

D1 chose the option WordPress itself is best known for getting wrong: plugins that run real
backend PHP, uploaded as a package rather than reviewed line-by-line by the platform's own team
before every change. The user's own framing — "a lot of controls in place for security and
safety" — is not a suggestion to hex this document with extra checks; it is this document's job
description. Every other file in this plan is either upstream of this one (what gets built,
[02](02-architecture-overview.md), [03](03-database-schema.md), [04](04-manifest-and-package-format.md))
or downstream of it (how it's operated, [10](10-review-and-publishing-pipeline.md),
[13](13-observability-and-incident-response.md)). This document is where the actual safety
claims are made and has to be the thing re-read, and re-verified against the real
implementation, before this system is ever pointed at a plugin nobody on the team wrote.

## 1. Threat model

| Actor | Capability | What must still hold |
|---|---|---|
| **A malicious developer**, from the first submission | Controls 100% of the code inside a plugin package | Cannot read/write any data outside its own install's scope within the one deployment it's running in, cannot escalate beyond its own granted capabilities, cannot reach this deployment's own core database directly, cannot execute outside its sandbox, cannot exfiltrate over the network outside an allowlist |
| **A developer whose account is compromised after publishing** | Can submit a new, malicious version of a plugin many tenants already trust | The new version goes through the same review as if from a stranger (04 §6); a kill switch reaches every independently deployed tenant that installed it within minutes, via push-with-pull-guarantee, not by editing a shared table (§7, [02 §8](02-architecture-overview.md)) |
| **A plugin that is not malicious but is buggy or resource-hungry** | Infinite loops, memory leaks, N+1 storms against the capability API | Resource limits (§6) and the circuit breaker ([13 §2](13-observability-and-incident-response.md)), local to this deployment, contain it without operator intervention |
| **A tenant admin who over-grants** | Approves capabilities without reading them | The consent screen (§4, [09 §3](09-admin-dashboard-ux.md)) shows exactly what was granted and lets it be revoked later without uninstalling — the mistake is recoverable |
| **An attacker who compromises the Plugin Runtime process/container itself** | The nightmare case — treated as *possible*, not dismissed. Runs inside one tenant's own deployment, alongside every other plugin *that same tenant* has installed | It has no credential for this deployment's own core database to steal (§2), so a runtime compromise does not become a database compromise; blast radius is bounded to what that one runtime instance was actively holding in memory for its current invocation — and it cannot simply *claim* a different, more-privileged **install** (a different plugin also running in this same deployment) to escape even that bound, because every callback it makes is checked against a credential it cannot mint (§10). It has no path to any *other* tenant's deployment at all — there is no shared runtime or database to reach (00 §0) |
| **An attacker who obtains the release-signing private key** | Held centrally, on the marketplace (the Brain) — could sign an arbitrary malicious release that every tenant's own Plugin Runtime and Plugin Host would then accept as legitimate | Custody, rotation, and a compromise-response procedure exist for this key specifically (§11) — this is treated as its own threat, not folded into "a malicious developer," because a key compromise bypasses the review pipeline entirely rather than merely lying to it |
| **An anonymous site visitor**, on a public site slot ([06 §2.1](06-extension-points-and-hooks.md)) | Can send any request to a plugin's public endpoint, on the one tenant's website they're actually visiting, with no session and no reason to trust anything they claim | Cannot reach any capability not explicitly marked eligible for public use (§12), cannot read anything beyond a collection the manifest itself declared public (§12) — a public endpoint is otherwise write-only by construction. There is no "which tenant" question to get wrong: the deployment answering the request only ever holds that one tenant's data (00 §0) |

## 2. Trust boundaries

```
                       ┌─────────────────────────────────────────┐
                       │              Core Platform                │
                       │  (this app's existing PHP-FPM workers)    │
                       │  full framework access, as today          │
                       └───────────────┬─────────────┬─────────────┘
                                        │             │
                          capability-scoped API   emits extension-point
                          calls FROM plugin        events TO plugin runtime,
                          runtime, presenting      carrying a fresh signed
                          the invocation token     invocation token (§10)
                          issued for this call     (02 §6) — all of this local
                                        │             │ to one tenant's own deployment
                                        │             │
                       ┌────────────────▼─────────────▼────────────┐
                       │             Plugin Runtime                  │
                       │  separate process/container per §3          │
                       │  NO shared-DB credential                    │
                       │  NO filesystem access beyond its own sandbox│
                       │  NO outbound network beyond the egress proxy│
                       │  ONLY the capability SDK object (§4)        │
                       └───────────────┬───────────────────────────┘
                                        │ allowlisted egress only, via proxy (§6)
                                        ▼
                                 external services
                                 (only if `http.egress` granted)
```

The boundary is drawn so that **compromising the Plugin Runtime does not, by itself, compromise
anything else** — it has nothing of value to steal beyond what the current invocation legitimately
holds, because it was never handed a shared-database credential, a core filesystem path, or an
unrestricted network stack. Nor can it *talk its way* past that boundary: every callback it makes
into Core Platform presents the invocation token minted for that specific call (§10), so a
compromised Runtime cannot simply assert a different tenant or install and be believed — the
boundary is enforced by what Core Platform independently verifies, not by what the Runtime
reports about itself. This mirrors the exact posture
[storage-sharding](../storage-sharding/08-security.md) already uses for its own separate-server
trust boundary ("the storage app never touches the shared database... a compromised storage
server cannot mint upload tokens or read another server's files") — same idea, applied to a
process/container boundary within one app instead of a physical server boundary between two apps.

## 3. Execution isolation mechanism (00 §6 Q1)

Three options were weighed. The recommendation is **B**, with **A** as the fallback if the
infrastructure work B needs is not available for the first delivery, and **C** flagged as future
work rather than dismissed.

| Option | Mechanism | Isolation strength | Cost |
|---|---|---|---|
| **A — `disable_functions` + `open_basedir` jail, same host** | A dedicated, minimally-privileged PHP-FPM pool, OS-user-separated from the main app, `disable_functions` removes every process/exec/eval-family function at the `php.ini` level (belt, not suspenders — the capability SDK is the real boundary, per §4), `open_basedir` restricted to the plugin's own sandbox directory | Real but shares the kernel and the machine with the main app | Lowest — no new infrastructure, works on the existing Laragon/lab hosts described in [AGENTS.md §6](../../../w/AGENTS.md) |
| **B — containerized worker pool (Recommended)** | Each Plugin Runtime invocation runs inside a container (or a pooled, recycled container per plugin+tenant) with no shared-database network route, no host filesystem mount beyond the plugin's own volume, and its own resource cgroup | Kernel-level isolation from the host and from other plugins' containers; a container escape is a known, bounded class of vulnerability with mature mitigations, unlike a shared-PHP-process escape | Real, but proportionate to what's being built — this is the option that actually satisfies "cannot read the shared database" as an infrastructure fact rather than an application-level promise |
| **C — WASM-compiled PHP runtime** | Plugin PHP compiled/interpreted inside a WASM sandbox with no syscall access at all | Strongest — a WASM module physically cannot make a syscall, so there is no host filesystem or network to escape *to* | Immature for a full Laravel-adjacent PHP surface today; flagged as a future hardening step once the capability SDK's shape (§4) is proven, not attempted in the first delivery |

**Decision:** build against option B. The Plugin Runtime is a small, purpose-built service (not
a general framework) that:

- Accepts one RPC per invocation: `{ install_id, entry: hook|capability_call, payload, grants, invocation_token }` — no tenant field, because a Plugin Runtime instance belongs to exactly one tenant's deployment and never receives a request on behalf of any other (00 §0). The `invocation_token` is minted by Core Platform for this call alone (§10) and is what every capability-SDK callback the plugin's code triggers must present back; the other fields describe the call, the token is what makes the call's claimed `install_id` trustworthy.
- Loads exactly one release's backend artifact (verified against its signature and hash, §2/4 in
  [04](04-manifest-and-package-format.md)) into a fresh or pooled-and-reset container.
- Returns a result or a bounded error; the container is recycled or destroyed per the pooling
  policy in §6.
- Never receives a shared-database credential, a Redis credential, or any secret beyond what a
  specific capability grant requires for that one call (e.g. a short-lived, capability-scoped
  token if a capability legitimately needs one — see §4), and never receives the ability to mint
  its own invocation token (§10) — that key never leaves Core Platform.

If B's infrastructure genuinely cannot be stood up for the first delivery, A is an acceptable
interim **only** if paired with everything in §4 unchanged — the capability-scoped SDK is not
optional under either option, because it is what stops "isolated from the OS" from being the only
line of defense.

## 4. The capability-scoped SDK — the boundary that matters even inside the sandbox

This is D7, made concrete. A plugin's PHP code is never hosted inside Laravel's own service
container and is never handed `app(SomeModel::class)`, `DB::table(...)`, `Http::get(...)`, or
`Storage::disk(...)`. It receives exactly one object, conventionally `$plugin` inside a hook
handler, whose methods are a closed set, each corresponding to exactly one row in the shipped
`config/plugin_capabilities.php` catalog ([03 §0](03-database-schema.md) — this is code, not a
database table, since every deployment runs the same codebase). That object's **install** scope
is fixed by the invocation token (§10) the Runtime presents when constructing it — never by an
`install_id` the Runtime process merely asserts — so the "scope is applied by the SDK itself,
never trusted from plugin input" guarantee in the table below holds even if the Runtime process
itself has been compromised, not only against a hostile plugin. There is no *tenant* dimension to
this scoping at all: a Plugin Runtime instance belongs to one deployment, always, so the only
thing worth defending against here is one install (one plugin) reaching another's data within that
same deployment, not a cross-tenant leak that was never structurally reachable (00 §0):

| Capability key | SDK method | What it actually does |
|---|---|---|
| `orders.read` | `$plugin->orders()->find($reference_num)` | Returns a narrow DTO (not an Eloquent model) for one order **this deployment** owns — scoped to this install, never trusted from plugin input |
| `orders.annotate` | `$plugin->orders()->addNote($reference_num, $text)` | Writes to a specific, existing note-attachment mechanism the core app already exposes to first-party code — not a raw table write |
| `plugin_data.read` / `plugin_data.write` | `$plugin->storage()->get($collection, $key)` / `->put(...)` | Reads/writes only `plugin_data` rows scoped to this plugin's own `install_id` and only within collections declared in its manifest (04 §4) |
| `plugin_data.increment` | `$plugin->storage()->increment($collection, $key, $field, $amount = 1)` | Atomically increments one numeric field of one `plugin_data` row — added for [16](16-example-plugin-cookie-consent.md)'s "count of visitors who accepted" case, so a high-frequency event is one atomic counter update, not a row-per-event write. **This is the plan's answer to "should every plugin get its own database for this kind of thing" — no**: a plugin never gets a query language or a file handle (§5's reasoning), it gets exactly this one narrow, safe primitive, enforced the same way every other storage method is (own `install_id`, declared collection only) — **and `field`/`amount` are validated on every call against the collection's declared `increment.fields`/`max_amount`** ([04 §4](04-manifest-and-package-format.md)), not trusted from the caller, since this method is reachable from an unauthenticated public request (§12) where the caller may not be the plugin's own code at all |
| `plugin_data.list` | `$plugin->storage()->list($collection, $keyFrom, $keyTo)` | Also added for [16](16-example-plugin-cookie-consent.md) — reading a *range* of rows (e.g. every daily-counter row between two dates) is a real, ordinary need `plugin_data.read`'s single-key lookup doesn't cover, and the alternative is a plugin looping `read` once per key, which is not a query language exactly the way `05 §5` rules one out, but is exactly the kind of chattiness that pattern exists to avoid. Same scoping as `read` (own `install_id`, declared collection only) and bounded (a fixed max row count per call, since this is still not an arbitrary query — no filtering, no sorting beyond key order, nothing beyond "the rows whose key falls in this range"). **Not** marked `reachable_from_public_site` — listing is an admin-side need only in the first delivery |
| `nav.register` | *(frontend-side; no backend SDK method — declared in the manifest, rendered by the Plugin Host, [08](08-frontend-runtime-and-dynamic-loading.md))* | |
| `http.egress` | `$plugin->http()->request($url, ...)` | Only reaches hosts the manifest **declared by name** ([04 §3](04-manifest-and-package-format.md)) and the tenant then approved verbatim at grant time — the tenant approves or rejects the developer's declared list, never types or edits it (this is now a resolved, single answer; see 04 §3's note on why); routed through an egress proxy that enforces the allowlist independently of the SDK code, so a bug in the SDK is not the only thing stopping arbitrary egress, and that proxy validates the *resolved address*, not just the hostname (§6) |
| `schedule.register` | *(declared in manifest; no ad hoc `Cron::` access)* | The platform's own scheduler invokes the plugin on the declared cadence, at most once per the manifest's stated interval — a plugin cannot self-schedule an arbitrarily tight loop |

**The rule with no exception:** if a capability is not in this table, there is no way for plugin
code to do the underlying thing at all — not "it would need permission," but "the method does not
exist in the object it was handed." This is why the manifest's declared capability list
([04 §3](04-manifest-and-package-format.md)) is checkable by the static analyzer
([10 §3](10-review-and-publishing-pipeline.md)): the SDK surface is small and closed enough to
enumerate every call site against it.

The SDK is versioned alongside the extension-point catalog ([06 §4](06-extension-points-and-hooks.md)).
A capability's underlying implementation can change; its method signature and its scoping
guarantee (install isolation — the only isolation dimension that exists inside one deployment,
00 §0) cannot change within a version without a major version bump the manifest's
`platform_compat` range must account for.

## 5. Why a plugin never gets its own SQL schema

Stated in [03 §3](03-database-schema.md) as a schema fact; the reasoning belongs here. A plugin
with `CREATE TABLE` power — even sandboxed, even reviewed — has:

1. **An unbounded static-analysis surface.** Reviewing "does this SQL do anything dangerous" for
   arbitrary developer-authored DDL and queries is a much larger and more error-prone review
   problem than reviewing "does this code call an SDK method it didn't declare."
2. **A direct threat to this deployment's own core tables, not merely a coordination problem.**
   A plugin's migration, run at install time, executes against the **same physical local
   database** this deployment's own `laravel_*` tables live in (00 §0 — there is no separate
   "shared database" a plugin's schema could be safely quarantined into the way
   [AGENTS §5](../../../w/AGENTS.md) quarantines one app's tables from another's across the
   estate). A malicious or buggy plugin migration could `ALTER` or `DROP` a core table directly,
   in the same database, with no cross-app boundary to stop it — a materially worse outcome than
   AGENTS §5's own documented cross-app migration failures, which were data left inconsistent, not
   a third party with `DROP TABLE` reach into the core app's own schema.

The `plugin_data` document store (`03 §3`) trades some developer convenience (no relational
queries across a plugin's own data) for closing this surface entirely. A plugin that outgrows a
key-value document model is exactly the kind of plugin that should talk to the review team about a
first-party capability, not route around the restriction.

## 6. Resource limits and the egress proxy

| Control | Limit | Enforced by |
|---|---|---|
| Execution time per invocation | Short, hook-kind-dependent (a `filter` hook's timeout is tighter than a `notify` hook's, per [02 §6](02-architecture-overview.md)) | Container/process supervisor, not application code — a hung plugin cannot out-wait an application-level timer that itself might not fire under load |
| Memory per invocation | Fixed ceiling, container cgroup | Container runtime |
| Outbound network | Denied by default; `http.egress` grants access only to the specific hostnames declared in the manifest and approved at grant time | A dedicated egress proxy the container's network namespace is forced through — not a `curl` allowlist inside PHP, which a container-level compromise could bypass |
| Concurrent invocations per plugin, in this deployment | Bounded, default conservative | Plugin Runtime dispatcher's own admission control |
| `plugin_data` row count / size per install | Bounded, configurable per plugin tier | Application-level check in the storage SDK method (§4), backstopped by a scheduled audit job |
| Scheduled-hook cadence | Cannot exceed the manifest's declared interval | Platform scheduler invokes, not the plugin |

### 6.1 The egress proxy validates the destination, not just the name

A hostname allowlist alone is not a network boundary — it is a DNS lookup away from being one. If
the proxy simply resolves the allowlisted hostname and connects to whatever comes back, an
attacker who controls that hostname's DNS (or triggers a rebind between the allowlist check and
the actual connection) can point an approved name at an internal address and the proxy would
forward the request, because the *name* matches even though the *destination* does not. The proxy
therefore:

- **Resolves and validates the IP address on every connection**, not once at grant time — rejects
  a resolved address in any private, link-local, loopback, or cloud-metadata range (`127.0.0.0/8`,
  `169.254.0.0/16` — this specifically covers cloud metadata endpoints, `10.0.0.0/8`,
  `172.16.0.0/12`, `192.168.0.0/16`) regardless of what hostname resolved to it.
- **Does not follow redirects across the boundary.** An allowlisted host returning a `3xx` to a
  non-allowlisted or internal target is not transparently followed; the plugin's request ends at
  the redirect, surfaced as a plain response the plugin's own code can act on if it chooses to
  request the new location explicitly — which is then checked again from the start.
- **Pins the resolved address for the lifetime of one request** rather than re-resolving between
  the check and the connect, closing the classic DNS-rebinding TOCTOU window.

This is enforced by the proxy infrastructure, independent of the capability SDK (§4) — the same
"a bug in one layer is not the whole defense" posture §0 states for the plan generally.

## 7. The kill switch

Any published release can be revoked (`brain_plugin_releases.revoked_at`,
[03 §1](03-database-schema.md)) by the marketplace team, centrally, on the Brain. On revocation:

- No tenant deployment can newly pull and install that release (already covered by
  `brain_plugin_catalog.status`/`review_status` gating in the marketplace's catalog API).
- **Every deployment that already installed it learns of the revocation two ways** — matching
  [02 §8](02-architecture-overview.md)'s reused `www_brain` channel: a push notification over the
  signed `brain-app` path, for speed, and its own next ordinary pull (checking
  `last_checked_at`/revocation status for its installed releases), as the guarantee that doesn't
  depend on the push arriving. Whichever happens first, this deployment's own Plugin Runtime
  dispatcher moves the local `laravel_plugin_installs` row to `disabled` and refuses to execute
  that release from then on — there is no shared table for the marketplace to edit directly on a
  tenant's behalf; it can only inform, never reach in.
- The tenant's own admin sees a clear, non-alarmist notice on next login ("this plugin was
  disabled by the marketplace; here's why") — not a silent disappearance, per the
  incident-response runbook in [13 §5](13-observability-and-incident-response.md).
- This reaches every independently deployed tenant that installed the release, each on its own
  local database, and requires no tenant action, satisfying G6 directly.

## 8. What a compromised or malicious plugin can and cannot do

| Can | Cannot |
|---|---|
| Do anything within its own granted capabilities, on the one deployment that installed it | Run at all on a deployment that has not installed it, or act beyond a capability that deployment has not granted |
| Read/write its own `laravel_plugin_data` rows and settings, local to this deployment | Read another plugin's `laravel_plugin_data`, this deployment's own core tables directly, or — since there is no shared runtime at all — anything belonging to any other tenant's deployment (00 §0) |
| Reach hosts this deployment's admin explicitly allowlisted for `http.egress` | Reach any other host, or reach this deployment's own core database, Redis, or the filesystem outside its sandbox |
| Consume resources up to its fixed per-invocation limits | Exceed those limits, or affect the request that triggered a `notify` hook (which is fire-and-forget by design, [02 §7](02-architecture-overview.md)) |
| Be revoked, disabled, or uninstalled by this deployment's own admin at any time | Prevent its own revocation, disablement, or uninstallation, or persist code execution after `state` leaves `active` |
| Be updated by its developer to a new version | Have that new version installed anywhere before it clears review again (04 §6) |

## 9. Frontend sandboxing — summary, detail in 08

The backend boundary above has a frontend counterpart: a plugin's UI bundle never runs in the
admin dashboard's own document context, only inside a sandboxed iframe communicating solely
through `postMessage` against the `plugin-host` contract. This matters because the admin
dashboard's own page holds the session, CSRF tokens, and everything `app.window.*` exposes
(01 §2) — an iframe boundary is what stops a compromised or malicious plugin frontend bundle from
reading any of that directly, the same way the backend boundary stops its PHP from reading the
shared database directly. Full design in [08 §3](08-frontend-runtime-and-dynamic-loading.md).

## 10. Invocation credentials — the Runtime cannot assert its own trust

§1's threat model treats a compromised Plugin Runtime process as a live possibility, not a
hypothetical — so nothing past that boundary should depend on the Runtime being honest about
*which install* it is acting for on a given callback (a deployment can have several plugins
installed at once, each its own install; there is no tenant dimension to get wrong, only this
one — 00 §0). Before this revision, §2–§4 described the RPC into the Runtime as carrying
`install_id` directly, with no statement of how a callback *out* of the Runtime, back into Core
Platform's capability API, was checked against that same value rather than whatever the callback
payload itself claimed. This section closes that gap.

- At dispatch ([02 §6](02-architecture-overview.md)), Core Platform mints a short-lived
  **invocation token**: a signed structure over
  `{ install_id, granted_capabilities, capability_scope, exp, nonce }`, using a signing key
  **distinct from** the release-signing key in [04 §5](04-manifest-and-package-format.md) and in
  §11 below — the two keys protect different things and rotating one must never require touching
  the other.
- The token travels with the RPC (§3's `invocation_token` field) and is opaque to the plugin code
  itself — it is never exposed through the capability SDK (§4)'s public methods, only carried by
  the SDK object's internal transport.
- **Every capability-SDK callback the Runtime makes presents this token, and Core Platform
  verifies its signature, expiry, and nonce — rejecting a replay — itself, rather than trusting
  the `install_id` a callback payload asserts.** The SDK object handed to plugin code (§4) is
  constructed *from the verified token's claims*, never from parameters the Runtime process
  supplies at call time — so a compromised Runtime cannot simply assert a different, more
  privileged install's identity on a callback and have it honored.
- Token lifetime is bound to one invocation — seconds, not the plugin's execution budget (§6) plus
  margin — so a compromised Runtime that captures a token cannot replay it against a later,
  unrelated event; the nonce check makes even a same-window replay fail.
- **Recommendation: asymmetric signing**, the same shape as
  [storage-sharding](../storage-sharding/08-security.md) §3's token scheme, applied here between
  Core Platform and its own Plugin Runtime rather than between two separate servers. Asymmetric
  costs more per verification than a shared HMAC secret, but means the Runtime — even fully
  compromised, even if it recovers whatever key it holds for verification — cannot mint a token of
  its own, only verify one Core Platform already signed. A symmetric scheme would require the
  Runtime to hold the same secret Core Platform signs with, which defeats the purpose: the whole
  point is a compromised Runtime cannot manufacture trust, only spend what it was already handed
  for the current call.

### 10.1 The dispatch direction needs the same treatment, not just the callback

Everything above authenticates the Runtime **calling back into** Core Platform. Nothing so far
authenticates the other direction — Core Platform **dispatching into** the Runtime in the first
place (§2's diagram, the `emits extension-point events TO plugin runtime` arrow). Left
unaddressed, that asymmetry is real: anything able to reach a Runtime instance's RPC surface within
this one deployment — a compromised neighbor in a pooled container fabric shared across this
deployment's own several installed plugins (option B, §3, explicitly allows "a pooled, recycled
container per plugin"), or lateral movement elsewhere on this deployment's own network — could
submit a forged dispatch with an attacker-chosen `install_id` and payload and get that plugin's
code to execute outside Core Platform's own control. That forged dispatch's own *outbound*
callbacks would still fail (§10 above covers that), but the code already ran by then, against
attacker-supplied input, inside a real plugin on a real tenant's deployment.

So the dispatch direction is authenticated and encrypted the same way
[storage-sharding 08](../storage-sharding/08-security.md) treats its own two-way boundary, not
left to an assumption that the network path is trusted:

- **Every dispatch from Core Platform to the Plugin Runtime is itself signed** — with the same
  invocation-token key described above, since a dispatch *is* the invocation token's issuance, not
  a separate credential. A Runtime instance refuses to execute anything from a dispatch whose
  signature it cannot verify against Core Platform's known public key, exactly mirroring how Core
  Platform refuses a callback whose token it cannot verify.
- **The transport between Core Platform and every Plugin Runtime instance is TLS** (or an
  equivalent encrypted channel if both sides are colocated behind a private container network),
  with certificate/identity validation on both ends — the same requirement
  [storage-sharding 08 §8](../storage-sharding/08-security.md) states explicitly for its own
  analogous boundary. This document previously left this unstated; it is not optional.
- With both of these in place, the trust boundary in §2 is symmetric: neither direction depends on
  "the network happened to only let the right process through."

## 11. Signing-key custody and compromise response

D6 and every downstream signature check in this document ("verify the signature before executing,"
§2 and §3; "the raw upload never executes," 04 §5) reduce to one fact: the release-signing private
key is never usable by anyone but the review pipeline. Previously this plan stated *that* a
signature is checked without stating how the key behind it is protected or what happens if it
isn't. This section is that missing half.

- **Custody.** The release-signing private key is held in a dedicated key-management service or
  HSM — never in application config, an environment variable, or a database row readable by the
  main application's own credentials. The signing operation (04 §5 step 4) is a narrow API call
  the review pipeline (10 §5) makes to that service; the pipeline's own code never holds the key
  material directly, so compromising the pipeline's application server does not, by itself, yield
  the key. This is deliberately a stricter posture than the per-server secrets in
  [storage-sharding 08 §2](../storage-sharding/08-security.md), which are encrypted-but-recoverable
  at the Brain by design for a different reason (HMAC verification there needs the key itself) —
  the release-signing key has no equivalent need to ever be read back out by an application
  process.
- **A signature is not authorized by "the pipeline is allowed to call sign()" alone — it is
  authorized by an actual, checked review approval.** Custody of the key stops an attacker from
  stealing it; it does nothing to stop a *compromised review-pipeline application* (its code, not
  the key) from calling the same signing endpoint the legitimate pipeline calls, for an artifact
  that was never actually reviewed — which would defeat D6 just as completely as a stolen key
  would, without ever touching the HSM. So the signing service enforces this itself, independent
  of the calling application's own correctness: a sign request must present a **reviewer-issued
  approval assertion** — a separate, short-lived signed statement, issued only by the manual-review
  step ([10 §4](10-review-and-publishing-pipeline.md)) once a specific reviewer approves a specific
  artifact digest — and the signing service verifies that assertion (digest match, not expired,
  not already consumed) before it will sign anything. A compromised pipeline application can still
  call the sign endpoint, but it cannot manufacture a reviewer's approval assertion for an artifact
  no reviewer actually looked at, the same "cannot manufacture trust, only spend what was already
  issued" posture §10 applies to the Runtime.
- **Every signed release records which key signed it.** `plugin_releases` carries a
  `signing_key_id` alongside `signature` ([03 §1](03-database-schema.md)) — without it, once the
  key is ever rotated, nothing says which of the (now several) historically-valid public keys a
  given release's signature should be checked against, and §11's own compromise response (below)
  cannot cleanly say "stop trusting releases signed under *that* key" without knowing which
  releases those are.
- **Separation from the invocation-token key (§10).** The two keys protect different things — a
  release artifact's provenance versus one live invocation's tenant context — and are rotated on
  independent schedules. Compromising one must not implicate the other, and rotating one is never
  a reason to also rotate the other.
- **Rotation (routine, non-compromise).** A stated cadence (recommended: annually). This is
  **not** the token-rotation pattern §10 borrows from
  [storage-sharding 08 §3](../storage-sharding/08-security.md) ("retire the old one after the
  longest token lifetime") — that pattern fits a short-lived credential that naturally expires and
  gets reissued; it does not fit a **permanent artifact signature** that a release published years
  ago still needs to verify against. Re-signing the entire historical catalog on every scheduled
  rotation is not a real operational plan, so this document does not ask for one: **every
  historically-issued public key remains valid for verification indefinitely**, selected per
  release by its recorded `signing_key_id`, exactly the way a TLS certificate authority keeps old
  root keys valid for certificates already issued under them rather than reissuing every past
  certificate whenever it adds a new root. Routine rotation only changes which key *new* releases
  are signed with; it revokes trust in no existing release. "Stop accepting an old key" is reserved
  for the compromise path below, and only there.
- **Compromise response.** If the release-signing key is suspected compromised:
  1. Rotate to a new key pair immediately; the Plugin Runtime and Plugin Host stop accepting the
     old public key for any *newly installed or updated* release at once.
  2. Freeze new installs and updates fleet-wide — a stricter, temporary, marketplace-wide version
     of the kill switch in §7, not scoped to one release but to the marketplace's ability to vouch
     for *any* release — until every release published since the last confirmed-clean point is
     re-reviewed and re-signed under the new key.
  3. Existing installs are **not** automatically disabled by the rotation alone: they were signed
     under a key that was valid at the time, and disabling every tenant's every plugin on mere
     suspicion is a worse outcome than a bounded investigation window. An install is disabled
     individually, through the ordinary kill switch (§7), only if step 2's investigation finds
     that specific release was actually tampered with.
  4. This procedure — and who is authorized to trigger it — is exercised as part of the
     incident-response drill in [13 §5](13-observability-and-incident-response.md), alongside the
     malicious-plugin runbook already defined there. A signing-key incident is a different
     starting condition than a reported malicious plugin, but converges on the same tool (the kill
     switch); the drill should cover both starting conditions, not only the plugin-reported one.

## 12. Public, unauthenticated invocation

**Added for [16](16-example-plugin-cookie-consent.md)**, the first plugin needing a
[public site slot](06-extension-points-and-hooks.md) §2.1. Every capability call described so far
(§4) is invoked either from an authenticated admin-dashboard session (§9/08) or from a backend
hook the platform itself dispatches (§10) — there was no path for a call that starts with an
anonymous visitor on the open internet. That path needs its own rules, not a relaxation of §4's.

**Corrected 2026-09-27** along with the rest of this document (00 §0): this section originally
resolved "which tenant" a public request belonged to by inspecting the request's domain, as a
multi-tenant app would have to. That step doesn't exist any more, because the question doesn't
either — **the deployment answering an HTTP request is, definitionally, the one tenant it
answers for.** There is no other tenant it could be, and nothing to look up to find that out. What
a public `site.global` request still has to identify, locally, is *which install* — this
deployment may have several plugins installed, each with its own public endpoint — and that
comes from the plugin's own slug, already present in the URL path
(`/plugins/{slug}/public/...`), resolved with one local lookup against this deployment's own
`laravel_plugin_installs` table. No domain resolution, no cross-tenant ambiguity, nothing the
client could spoof to redirect a call toward a different deployment's data — there is no such
data reachable from here to redirect toward.

- **Only capabilities explicitly marked eligible are reachable at all.** The shipped
  `config/plugin_capabilities.php` catalog (§4, [03 §0](03-database-schema.md)) marks
  `reachable_from_public_site` per capability, default `false`. `plugin_data.increment` is marked
  eligible unconditionally; nothing at `risk_tier: high` is eligible in the first delivery. The
  Plugin Runtime dispatcher refuses a call to an ineligible capability from a public invocation
  path regardless of what the manifest declares, the same closed-by-default posture §4's "if it's
  not in the table, there is no method" already applies elsewhere.
- **`plugin_data.read` is the one narrower case: reachable from a public invocation, but only for
  a collection the manifest itself declared `public: true`**
  ([04 §4](04-manifest-and-package-format.md)) — not a blanket public-read grant. A public toast
  needs to read its own banner text to render at all; it does not need, and cannot get, read
  access to any other collection the same plugin might use for admin-side data. The dispatcher
  checks the declared collection's `public` flag on every such call, the same way it already
  checks the declared collection list itself (04 §4) — a plugin cannot read a non-public collection
  from this path by any means, including one it declared for its own admin settings.
- **`plugin_data.increment`'s `field` and `amount` are validated against the target collection's
  declared `increment.fields`/`max_amount`** ([04 §4](04-manifest-and-package-format.md)) **on
  every call, at the dispatcher, before anything is written.** This is the control that actually
  matters on a public endpoint: the request body reaching this path did not necessarily come from
  the plugin's own toast script at all — nothing stops a direct POST from any HTTP client. A
  request naming a field the manifest didn't declare, or an amount above the declared bound, is
  rejected outright, the same fail-closed posture as every other check in this section — the
  plugin's manifest is trusted to declare its own bounds correctly (checked at review,
  [10 §2–§3](10-review-and-publishing-pipeline.md)), the request body is trusted for nothing.
- **No trusted "parent" to mediate through, unlike the admin dashboard.** §9/08's admin iframe
  routes every call through `postMessage` to the admin shell specifically because that shell holds
  the session/CSRF context the iframe must never touch directly. A `site.global` iframe on a public
  page has no equivalent surrounding trusted code — the tenant's own published page is just markup,
  not platform code — and nothing sensitive to protect that way, since the call is anonymous by
  design. So its CSP scopes `connect-src` narrowly to the plugin's own public endpoint
  (`/plugins/{slug}/public/...`, on this same deployment) and it calls that endpoint directly,
  rather than relaying through a parent that has no special trust to offer here. Every other CSP
  restriction in [08 §3](08-frontend-runtime-and-dynamic-loading.md) still applies unchanged.
- **Basic abuse limiting** on the public endpoint (rate-limited per IP/session) — proportionate to
  what's actually at stake here: an inflated or deflated consent counter is a display statistic,
  not a security-critical value, so this is ordinary hygiene, not a hardened control.
- **No session, no CSRF token, and none needed** — there is nothing to authenticate a visitor
  *as*, so none of §9's admin-session machinery applies; the public endpoint's entire security
  model is "resolve which install locally, restrict what's reachable, rate-limit the rest."

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Trust-boundary decision finalized (option B or A, §3), the shipped capability catalog config (§4, [03 §0](03-database-schema.md)) authored, the SDK's closed method set implemented against a stub Plugin Runtime, invocation-token issuance and verification (§10) wired into every Runtime dispatch and every capability callback, dispatch-direction signing and transport encryption (§10.1) | A hook handler with no matching grant is refused before any plugin code runs; the SDK object has no method that isn't in `config/plugin_capabilities.php`; a capability callback presenting no token, an expired token, or a reused nonce is rejected regardless of what `install_id` it claims; a Runtime instance refuses a dispatch it cannot verify as genuinely from Core Platform |
| **P1** | Signature verification (04 §5) enforced on every Plugin Runtime invocation and every frontend bundle load, selecting the public key by `signing_key_id`; release-signing key custody, the reviewer-approval-assertion gate, routine rotation, and the compromise-response procedure (§11) established before the first real release is ever signed | An artifact with a mismatched hash or an unverifiable signature is refused, logged, and never executed; the signing key is demonstrably never resident in the main application's own process or config; a sign request lacking a valid reviewer approval assertion for its exact digest is refused even from an authenticated pipeline call; a release signed under an old, non-compromised key still verifies correctly after a routine rotation |
| **P2** | Resource limits and the egress proxy (§6), including per-connection resolved-IP validation and redirect handling (§6.1) | A plugin attempting unbounded execution, memory, or an unlisted host is stopped, not merely logged after the fact; an allowlisted hostname that resolves (or is rebound) to a private/link-local/metadata address is refused, and a redirect off an allowlisted host to a non-allowlisted target is not followed |
| **P3** | Kill switch (§7) exercised end to end | Revoking a release disables every existing install within the stated window with no tenant action required |
| **P4** | Deliberate adversarial testing: a reference "hostile" plugin attempting each row of §8's "cannot" column, **plus** a simulated compromised-Runtime callback asserting a false install identity (§10), a forged dispatch sent directly to a Runtime instance (§10.1), a signing request presenting no (or a stale) reviewer approval assertion (§11), and a simulated signing-key-compromise drill (§11) | Every attempt is rejected; this is [14](14-rollout-and-testing.md)'s sandbox-escape test matrix, not a separate exercise |
| **P5** | `plugin_data.increment` and the public, unauthenticated invocation path (§12), including `reachable_from_public_site` gating and the per-collection `public: true` read carve-out | A public request naming a different plugin's `install_id` in its body is ignored in favor of the slug resolved from the URL path against this deployment's own local table; a call to a capability not marked `reachable_from_public_site` is refused from that path even if the manifest declares it; a `plugin_data.read` against a collection not declared `public: true` is refused from the public path even if the same install can read it from the admin dashboard |
