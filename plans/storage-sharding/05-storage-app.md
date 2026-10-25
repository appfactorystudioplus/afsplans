# 05 — The storage app

## Completion document

When this plan step is completed, write `complete/05-storage-app.complete.md`.

---

## 1. What it is

A **Laravel application**, its own repository under `C:\projects\vagrant\afs-eco\w\` (the
eleventh; AGENTS §2 says ten and is updated as part of [11](11-lab-and-rollout.md)). Directory
name is `storage_device` (decided, [00 §6](00-overview.md)). One instance runs on each storage server and owns
**all** of that server's devices. Laravel was chosen for what the requirement needs from a
framework: **routing** (hostname → device, token-verified endpoints, streaming responses) and
the **scheduler** (cron-driven polling and sweeps).

No code goes to `packages/afsserver`.

## 2. What it does, and what it does not

| Does | Does not |
|---|---|
| Receives chunked uploads directly from clients, holds `.part` files, commits atomically | Authenticate members. It verifies **tokens members signed** |
| Serves ranged downloads | Know quotas, plans, buckets' meaning, or member accounts |
| Executes idempotent file operations members instructs: create folder, rename, move between buckets, delete, purge trash | Connect to the shared database (D3) |
| Measures devices and reports to the Brain | Decide placement, or which device a file belongs on |
| Pulls its configuration from the Brain | Trust any caller that is not members (signed) or a client (token) |

It is deliberately dumb about the estate: everything it knows about a session arrives in a
**grant** from members ([06](06-direct-upload-protocol.md)), and everything it knows about the
fleet arrives from the Brain ([03](03-registry-and-brain-admin.md) §5).

## 3. Routing by hostname

```
https://{N}.storage-device.{base_domain}/…
```

A middleware resolves `{N}` from `Host`, then asserts three things, in order, before any
controller runs: `N` is a positive integer; `N` is a device **this server hosts** (from pulled
config); the device is not locally `offline` (mount and marker check, [02](02-naming-and-addressing.md) §5). Failing any is a `404` with no
body detail — never a fallback to a different device. The resolved device is bound for the
request; a controller cannot address any other device's path.

## 4. Endpoints

Two surfaces, distinguished by who may call them.

**Client surface** — authenticated by an **upload/download token** ([06](06-direct-upload-protocol.md) §3), CORS-enabled for the members origins:

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/v1/sessions/{id}` | Authoritative resume offsets — the length of each `.part` |
| `PUT` | `/v1/sessions/{id}/files/{fileId}` | A byte range (`Content-Range`), streamed to disk, never buffered whole |
| `GET` | `/v1/files/{nodeId}/content` | Ranged download, `nosniff`, RFC 5987 `filename*` |

**Control surface** — authenticated by a **signed members→storage request** (HMAC, the
`brain-client` scheme, key = the members↔storage credential; [08](08-security.md) §2). Not
CORS-enabled, not reachable with a client token:

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/internal/sessions` | Accept a grant: session id, member ref, bucket, files `{fileId, relPath, size, sha256}` |
| `POST` | `/internal/sessions/{id}/commit` | Verify size/digest, re-validate the path, `rename(2)` into place; return `{fileId, size, sha256, mime, etag}` per file |
| `DELETE` | `/internal/sessions/{id}` | Cancel; remove staging |
| `POST` | `/internal/ops` | One idempotent file operation (`mkdir`, `rename`, `move_bucket`, `delete`, `trash`, `untrash`, `purge`), carrying an operation id so a retry is a no-op |
| `GET` | `/internal/health` | Liveness + a fresh measurement of the hosted devices |

**Brain surface** — `POST /api/internal/brain-app/refresh` (drop cached config and pull now) and `GET …/state`, verified with the server credential. The `brain-app/` receiver that
[www_brain 10 §10.5](../www_brain/10-brain-owned-config-and-push.md) describes is **planned, not built** in `afs/brain-client`, so this app implements these two routes itself with the same signature scheme. Pull on a schedule remains the source of truth; push is an accelerator only.

## 5. Path handling

`NameValidator` and the containment logic (`PathResolver::resolve()` with its `realpath()`
check, `.staging/{sessionId}/{fileId}.part` under opaque ids) are **ported into this app**, with
the cloud-storage traversal-corpus test. Members validates first (so a client gets an early
error), but the storage app **re-validates every path it is about to touch** — it is the
process holding the filesystem, so it does not take members' word for a path ([08](08-security.md) §3).

The fixture corpus that [cloud-storage 06 §7](../cloud-storage/06-name-validation.md) never
shipped becomes a shared artifact here: **one JSON corpus consumed by the members, storage-app,
TypeScript and Dart validators**. The port is otherwise a copy, not a rewrite.

## 6. Private state

The storage app keeps its own small database (SQLite by default; MySQL if the operator
prefers) for what it must remember across requests and restarts:

| Table | Holds |
|---|---|
| `sessions` | Grant: id, device, member ref, bucket, expiry, state |
| `session_files` | `fileId`, `relPath`, declared size/sha256, received bytes, state |
| `operations` | Operation ids and outcomes, so `/internal/ops` is idempotent |
| `device_state` | Last local measurements and the local `offline` reason |
| `config_cache` | Last-known-good pulled config, so the app works with the Brain down |

Offsets are still the **length of the `.part` on disk**, not a counter in this database — the
database records intent, the filesystem records truth, as in cloud-storage 03 §4.

## 7. The scheduler (cron)

`* * * * * php artisan schedule:run` on the storage server. Jobs:

| Job | Cadence | Does |
|---|---|---|
| `storage:poll-devices` | Every minute | Measure every hosted device and report to the Brain ([07](07-health-polling-and-reporting.md)) |
| `storage:pull-config` | Every 5 min, and on push | Refresh devices, capacities, public keys; keep last-known-good |
| `storage:sweep-staging` | Hourly | Delete `.staging/{id}/` for sessions past expiry that members never cancelled (defence in depth beside members' own sweep) |
| `storage:prune-ops` | Daily | Drop operation records past the retry window |

Operator commands: `storage:init-device {N}` (create the marker after confirming the mount),
`storage:devices` (list hosted devices and local health), `storage:report-now`.

## 8. Configuration

Read through `config()`, never `env()` at the call site. Environment: the Brain surface URL,
this server's credential, the members callback base URL, the private database DSN. *(Amendment 2026-10-11, [12](12-server-enrollment.md): the credential and reference are no longer `.env` values; the app enrolls with the Brain and keeps them, encrypted, in its private database. `.env` needs only `BRAIN_URL` and `APP_KEY`.)* Everything
else — devices, capacities, keys, `base_domain` — comes from the Brain.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Repository scaffold, hostname→device middleware, config pull with last-known-good, `storage:init-device`, `storage:devices` | A request to an unhosted `N` is `404`; the app starts with the Brain down using cached config |
| **P1** | Control surface: grant, commit, cancel, ops; ported `NameValidator`/containment and the traversal corpus test | A signed grant + commit lands a file at its real name under `/mnt/storage{N}` |
| **P2** | Client surface: offsets, ranged PUT, ranged GET, CORS | A client with a valid token uploads and downloads a multi-chunk file; a wrong-device or expired token is rejected |
| **P3** | Scheduler jobs and reporting ([07](07-health-polling-and-reporting.md)) | The Brain shows fresh figures for every hosted device |

## 9. As built (2026-09-26)

Repository: `C:\projects\vagrant\afs-eco\w\storage_device` (Laravel 12). **Not a git repository yet** — `git init`
and the remote are the user's to do; nothing was committed. There is no `vendor/` and no `composer.lock`: they
come from `composer install`. Tests were run against the members app's Laravel 12 vendor through a scratch
bootstrap, never from the repo.

- **P0** — scaffold; `ResolveDevice` (hostname → device → hosted → locally online; every refusal the same bare 404);
  `DeviceConfig` + `BrainClient::pullConfig()` (signed, ETag/304, last-known-good, a document naming another server
  or malformed is rejected); `storage:init-device`, `storage:devices`, `storage:pull-config`.
- **P1** — control surface: `POST /internal/sessions` (grant), `POST /internal/sessions/{id}/commit`,
  `DELETE /internal/sessions/{id}`, `POST /internal/ops`, `GET /internal/health`; `NameValidator` ported verbatim,
  `PathResolver` re-rooted per device; shared corpus in `tests/fixtures/path-corpus.json`.
- **P2** — client surface: `GET /v1/sessions/{id}`, `PUT /v1/sessions/{id}/files/{fileId}`,
  `GET /v1/files/{nodeId}/content`; RS256 token verification; CORS via `config/cors.php`.
- **P3** — scheduler (`routes/console.php`) and `storage:poll-devices` / `storage:report-now`, which send the
  07 §2 report. **The bounded retry queue is not built** — it is step 07 P0.

Decisions made while building, each of which a later step must honour or overturn:

1. **Headers.** Members → device: `X-Storage-Timestamp` / `-Nonce` / `-Signature`. Brain → device and device → Brain:
   `X-Brain-Server` / `-Timestamp` / `-Nonce` / `-Signature`. The signed *path* separates the directions.
2. **Grant additions.** Each grant file may carry `replace: true`; the device then moves an existing file to
   `.trash/replaced-{fileId}/` before the rename, otherwise an existing target fails that file with `EXISTS`.
   `conflict_policy` stays entirely members' business (`keep_both` arrives already uniquified).
3. **Commit is per-file.** The commit response is `{committed[], failed[]}`; the session only becomes `committed`
   (and staging is removed) when nothing failed, so the client can finish a short file and commit again.
4. **Ops.** `mkdir`, `rename`, `move_bucket`, `delete`, `trash`, `untrash`, `purge` — arguments as documented in
   `FileOps`. `trash` moves into `.trash/{trash_id}/{name}`. **`untrash` added 2026-09-27** (10's "Known gap"
   closure) — the exact reverse of `trash`, `{member, bucket, rel_path, trash_id}`, moving
   `.trash/{trash_id}/<basename>` back to `{bucket, rel_path}`; needed because retention's restore-from-purging path
   has no other way to move bytes back out of `.trash/`.
5. **Token format and download claims** — see the corrections in [06](06-direct-upload-protocol.md) §3.
6. **Config document additions the Brain does not publish yet:** `members_key`, `members_key_next`, `token_keys`
   (`[{kid, public_key}]`). Until www's `StorageConfigBuilder` emits them, the members key comes from
   `STORAGE_MEMBERS_KEY` and **no token can verify** (no public keys) — so the client surface is closed, which is the
   safe default.
7. **Base domain comes from the pulled config**, not `.env`: with no config pulled, every request is a 404.

**Update (step 06, 2026-09-26):** decision 6 above is superseded — www's `StorageConfigBuilder` now publishes
`token_keys`, `members_key` (derived **for this server**, never the master) and `members_key_next`, so a storage app
verifies tokens and control calls from the pulled document alone. Verified end to end over real HTTP (see
[06](06-direct-upload-protocol.md) "As built").
