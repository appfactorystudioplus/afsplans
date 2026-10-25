# 06 — Registry Distribution and Resolution

> Renumbered 2026-09-22 (was `08-registry-distribution.md`) — section numbers below were
> renumbered from §8.x to §6.x to match, along with every cross-reference to them elsewhere
> in `plans/`. See `README.md`.

> **STUB, FOR AUDIT — banner stale, see `complete/06-registry-distribution.complete.md`.**
> Added after an audit found the registry is not one file but **eleven**, eight of them
> compiled into shipped JS bundles. The decision recorded here — *one file, resolved at
> runtime* — was taken by the owner once that was surfaced. **"Nothing below is built" is no
> longer true**: §6.5's steps 1–5 and 7 were found, on 2026-09-23, to already be built
> elsewhere in the estate (never tracked back to this document), and step 6 was completed
> that day. Only OQ-J (CORS, unverifiable from a sandboxed checkout) remains genuinely open.
> OQ-K (the 9-copy SDK question) was resolved twice on 2026-09-23 — first "stays
> copy-per-app," then, later the same day, reversed: `checkpoint/` is now
> `@appfactory/checkpoint-client`, a real published package — see §6.7's own OQ-K row for
> the full account. See the completion doc for what was found, what was built, and why this
> banner was left rather than rewritten line-by-line.

## 6.1 Why this document exists

[02](02-architecture.md) §2.1 draws the registry as a single box labelled *"declares the
POLICY"*, and every other document in this directory writes as though
`checkpoint_registry_v4.json` is one file that consumers read.

It is not. The audit below found eleven copies of the v3 registry, eight of which are
**build-time JSON imports** that Vite inlines into application bundles. A registry-driven
engine ([02](02-architecture.md)) and a registry-authored feature-flag system
(`conventions.feature_flags`) both rest on an assumption the estate does not currently
satisfy.

**This is a prerequisite, not a cleanup.** §6.6 explains why the fail-closed rule in
[02](02-architecture.md) §2.9 cannot be implemented while the current shape stands.

## 6.2 Current state — audited, not assumed

### The file

| Location | Kind |
|---|---|
| `auth/public/checkpoint/checkpoint_registry_v3.json` | **Canonical.** Served over HTTP from a public path |
| `auth/resources/js/checkpoint/` | Copy — no importer found |
| `apps/coordinator/resources/afs-connect/` | Copy — read by PHP via `resource_path()` |
| `apps/coordinator/resources/js/checkpoint/` | Copy — bundled import |
| `apps/dream_graph/resources/js/checkpoint/` | Copy — bundled import |
| `apps/flow/resources/js/checkpoint/` | Copy — bundled import |
| `apps/notes/resources/js/checkpoint/` | Copy — bundled import |
| `apps/pdf_editor/resources/js/checkpoint/` | Copy — bundled import |
| `market/resources/js/checkpoint/` | Copy — bundled import |
| `members/resources/js/checkpoint/` | Copy — bundled import |
| `www/resources/js/checkpoint/` | Copy — bundled import |

**Eleven files. Ten are copies.**

> Counts here and throughout exclude `packages/wwwafsportal_v1`, which is permanently out of scope by the owner's standing
> instruction. It holds its own copies of both the registry and the SDK; they are not
> part of this migration and are not to be edited.

Current drift: **none**. All eleven are byte-identical once line endings are normalised
(three distinct hashes, all CRLF/LF differences). They are in sync by discipline alone —
every one of them self-reports `"version": "3.0.0"`, so nothing could detect divergence if
it happened.

`alloc_v2.ts`, the client SDK, is duplicated the same way: **9 copies**, 1521 lines each,
all identical modulo line endings. `auth/public/checkpoint/samples/alloc_v2_sample.ts` §1
instructs the reader to *copy* it into each application's source tree.

### The consumers

| Consumer | How it resolves | Status |
|---|---|---|
| 7 × `…/checkpointHelper/static/registry.ts:10` | `import … from '…checkpoint_registry_v3.json'` | **Bundled at build time** |
| `www/resources/js/registry.ts:9` | same import, different path | **Bundled at build time** |
| `members/…/Storage/RegistryClient.php:65` | HTTP `GET {base}/checkpoint/checkpoint_registry_v3.json` | **Runtime fetch — already correct in shape**, wrong version |
| `apps/coordinator/…/ParseAllocationGateway.php:237` | `resource_path('afs-connect/checkpoint_registry_v3.json')` | Disk read, hardcoded, no config key |
| `auth/…/AllocationV2Controller.php:428` | `config('checkpoint.allocation.registry_path')` | Config-driven — **now resolves v4** |
| `auth/…/AllocationGateway.php:244` | same config key, `?? …v3.json` fallback | Config-driven — **now resolves v4**; fallback is dead but stale |

`auth/config/checkpoint.php:228` was updated to v4 during the audit. That change reaches the
two `auth` PHP readers and **nothing else**.

### The bundled import is a value, not a type

The distinction matters, because "it is only for type safety" would make this a non-issue.
It is not. `www/resources/js/registry.ts:44` passes the imported JSON as a **runtime value**:

```ts
registry: checkpointRegistryV3 as unknown as AllocV2Registry,
```

The file's own comment states the intent plainly:

> *"`registry` is the bundled copy of checkpoint_registry_v3.json that ships in the synced
> checkpoint/ directory. Passing it explicitly (rather than letting the client fetch it)
> keeps allocation free of a second network dependency and of any CORS consideration on the
> auth host."*

So the JSON is inlined into every shipped bundle. **Changing the canonical file changes
nothing for these eight consumers until each application is rebuilt and redeployed.**

### Type safety is a separate, hand-maintained thing — and it has already drifted

`RegistryResourceIds` (`www/resources/js/registry.ts`) is a hand-written constant map of
literal strings. It is **not** derived from the JSON. Checked against registry v4:

| | |
|---|---|
| Registry resources | **34** |
| `RegistryResourceIds` entries | **28** |
| Present in TS, absent from the registry | `domain_ecommerce` — **a key that does not exist** |
| Present in the registry, absent from TS | `afs_connect`, `secondary_users`, `domain_sponsorships`, `domain_feedback`, `domain_listings`, `domain_chat`, `domain_discussions` |

A phantom identifier and seven missing ones. Deriving the union from the registry
(`keyof typeof registry.resources`) makes both classes of drift impossible to express.

## 6.3 The decision

> **One file. `auth/public/checkpoint/checkpoint_registry_v4.json`. Resolved at runtime,
> never bundled. Every other copy is deleted.**

Rationale, in the owner's framing: the registry must be the one place you open to know where
something comes from. Eleven copies and eight build-time snapshots defeat that, and defeat it
silently — the copies agree today, so nothing signals the problem until they disagree.

Two consequences follow, and they are the reason this is worth doing rather than tidy:

1. **A feature flag that needs a rebuild is not a feature flag.** `conventions.feature_flags`
   declares `enabled` as a global kill switch. Under the current shape, flipping it means
   editing eleven files and rebuilding eight applications. Under runtime resolution it means
   deploying one file.
2. **Retention reads policy at sweep time.** An engine that deletes member data must not
   read a snapshot frozen at the last frontend build.

## 6.4 Target shape

```
   auth/public/checkpoint/checkpoint_registry_v4.json     ← the only copy
                          │
                          │  HTTP (cached)
          ┌───────────────┼────────────────┐
          ▼               ▼                ▼
     PHP consumers   JS consumers    Retention engine
     (server-side)   (authz/alloc)   (server-side, sweep)
```

Two resolution modes, both reading the same file:

| Mode | Who | Notes |
|---|---|---|
| **Server-resolved** | PHP: retention engine, allocation gateways | Read from disk on the auth host, or fetch + cache. `RegistryClient.php` already has the right shape |
| **Runtime fetch** | JS: `authz`, `alloc_v2` | `CheckpointAllocV2` **already supports this** — it accepts `registryUrl` as an alternative to `registry` (`alloc_v2.ts` §199). No SDK change needed, only a call-site change |

That the SDK already supports `registryUrl` is the single most important fact for costing
this work: the eight bundled call sites are a **configuration change**, not a refactor.

### Caching and availability

The objection recorded in `www/resources/js/registry.ts` — *"keeps allocation free of a
second network dependency and of any CORS consideration"* — is legitimate and must be
answered, not waved away:

- **Cache.** `authz.ts` already carries `cacheTtl` / `setCacheTtl()`. Point that machinery at
  the registry; it is a small, static, highly cacheable document.
- **CORS.** The file is served from a public path on the auth host. Whether the current
  headers permit cross-origin reads from every app origin is **OQ-J** (§6.7).
- **Fallback.** Optionally ship exactly one bundled copy per app *as a named fallback used
  only when the fetch fails* — explicitly stale, never an authority. This is a different
  thing from today's shape, where the bundled copy **is** the authority.

## 6.5 Migration sequence

Ordered so nothing breaks mid-flight. **Deletion is last, and it is last for a reason.**

| # | Step | Breaks if skipped |
|---|---|---|
| 1 | Publish v4 at the canonical public path (already there) and confirm it is reachable over HTTP from each app origin | — |
| 2 | Repoint `RegistryClient.php:65` → v4 | Storage keeps enforcing from v3 |
| 3 | Give `ParseAllocationGateway.php:237` a config key and point it at v4 | Coordinator keeps enforcing from v3 |
| 4 | Delete the two dead v3 fallbacks (`AllocationGateway.php:244`, `AllocationV2Controller.php:428`) and fix the stale comment at `:225` | Silent path back to v3 |
| 5 | Convert the 8 bundled imports to `registryUrl` + cache; rebuild and deploy each app | — |
| 6 | Derive `RegistryResourceIds` from the registry; delete the hand-written map | Phantom/missing keys persist (§6.2) |
| 7 | **Only now** delete the 10 copies | See below |

### What deleting early would break

| Deleted file | Immediate effect |
|---|---|
| Any of the 8 bundled copies | **Build failure.** The `import` cannot resolve; the app does not compile |
| `apps/coordinator/resources/afs-connect/…v3.json` | **Runtime failure** in `ParseAllocationGateway` |
| `auth/public/checkpoint/…v3.json` | **Runtime failure** in `members` storage quota — this is the live, shipped path |

The affected packages are independent git repositories (`auth`, `www`, `members`,
`market`, `apps/coordinator`, `packages/afsserver`), so deletions are recoverable — but the
sequence above avoids needing that.

### Files to delete at step 7

The 10 non-canonical `checkpoint_registry_v3.json` copies in §6.2, plus — once
`RegistryResourceIds` is derived and the SDK is no longer copy-per-app — the superseded
`checkpoint_registry.json` and `checkpoint_registry_v2.json` in `auth/public/checkpoint/`.

**Whether the 9 `alloc_v2.ts` copies collapse the same way is out of scope for this
document** and is noted as OQ-K.

## 6.6 Why §2.9 depends on this

[02](02-architecture.md) §2.9 requires:

> *Registry unreachable → deny new allocation. **Retire nothing.***

The existing loader cannot express that. `AllocationGateway::registry()` returns `[]` when
the file is missing, and `resolveLimit()` then yields `{ limit: 0, enabled: false }` — which
is **byte-identical to a legitimate plan-0 member**. A retention engine reusing that shape
would read an unreadable registry as *every member is over quota on everything*.

> **Requirement.** The registry loader must return a distinguishable **unknown**, never a
> zero. "Absent" and "zero" are different answers and only one of them is safe to act on.

This is the same defect as **B3** (`planFor()` treats a missing membership row as plan 0),
in a second location. Both belong in Phase 1. See [05](05-rollout-and-testing.md) §5.2.

A related symptom worth fixing in the same pass: because a missing registry produces
`enabled: false`, a server misconfiguration currently reaches the member as *"Your plan does
not include secondary users. Upgrade to add team members."* — an upsell generated by a
missing file. Only `tiers[plan].enabled === false` should ever produce upgrade UI.

## 6.7 Open questions

| # | Question |
|---|---|
| **OQ-J** | Do the auth host's CORS headers permit a cross-origin `GET` of the registry from every app origin? Step 5 depends on it |
| **OQ-K** | ~~Does the 9-copy `alloc_v2.ts` SDK collapse to one published package, or stay copy-per-app?~~ **Re-resolved 2026-09-23, same day, the opposite way: collapses to one published package.** Earlier that day this row said "stays copy-per-app" via the (then-just-fixed) `sync-checkpoint-client.sh` — "Option A" from `plans/alloc-v2-parse-direct/04-distribution-and-parity.md` §4.2, with a real package noted as "the eventual destination, not required by this refactor." Later the same day, the owner asked for that eventual destination directly: `resources/js/checkpoint/` (the whole directory, not just the 3-file sync set) is now `@appfactory/checkpoint-client`, published to a private registry (`packages/afsserver/docker/verdaccio/`). The sync script is deleted. See `plans/membership_change/complete/00_extras.md` for the migration record, including a real content-divergence finding the sync script's own narrow 3-file scope had been masking: several non-synced files (`client-handler.ts`, `index.ts`, `secondary-users.ts`, `types.ts`, plus two files — `app-urls.ts`, `app-names.test.ts` — that existed in `members`/`auth` only) had quietly diverged per-app, `www` was behind on most of them, and one of the "ahead" apps' extra files hadn't been noticed at all until this pass diffed every app's directory listing, not just the 3 files the script tracked. |
| **OQ-L** | Cache TTL for the fetched registry, and whether a flag flip is allowed to wait for it. A kill switch with a 1-hour TTL is a 1-hour kill switch |
| **OQ-M** | Is a bundled fallback copy kept at all (§6.4)? It reintroduces one copy per app — deliberately stale, but a copy |
| **OQ-N** | `version` changed type between registries: `"3.0.0"` (string) → `4` (int). Fix the format before anything compares versions |
