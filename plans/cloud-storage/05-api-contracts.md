# 05 — API contracts

## Completion document

When this plan step is completed, write `complete/05-api-contracts.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. Every route handler
documents its method, path, auth requirement, request shape, every response status it can
return, and its side effects.

---

> **Extended by [storage-sharding 06](../storage-sharding/06-direct-upload-protocol.md).** The
> routes below are all still current, but for a *placed* (device-hosted) session: `POST
> /sessions` additionally returns `upload_url`/`upload_token`/`upload_token_expires_at`; range
> PUTs go to that URL, not to members (`PUT .../files/{fileId}` here is the *local-mode-only*
> fallback — `storage-sharding/10` §1.2/§3); `GET /sessions/{id}` reports no per-file offsets
> for a placed session; a new `POST /sessions/{id}/upload-token` and `GET
> /files/{id}/download-url` exist. The manifest/preflight/commit/conflict semantics below are
> unchanged either way.

All routes live under `/api/v1/storage`, inside the `checkpoint.verify` middleware group
that [01-server-authentication.md](01-server-authentication.md) §5.1 extends to the whole
authenticated surface. The caller is resolved to `member_reference_num` (and `member_plan`)
before any handler runs; **no handler accepts a member identifier as a parameter**, and no
handler reads one from `query()`, `input()`, or `session()`.

## 1. Route table

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/storage/profile` | Buckets, capacities, usage, plan, the retention formula (doc 08 §4) |
| `POST` | `/api/v1/storage/scan` | Validate a manifest **without** creating a session — the preflight scan |
| `POST` | `/api/v1/storage/sessions` | Open an upload session; returns fileIds, offsets, conflicts, rejects |
| `GET` | `/api/v1/storage/sessions/{id}` | **Authoritative resume offsets.** Re-queried on every reconnect |
| `PUT` | `/api/v1/storage/sessions/{id}/files/{fileId}` | Upload a byte range |
| `PUT` | `/api/v1/storage/sessions/{id}/batch` | Coalesced small-file frame (§5.3) |
| `POST` | `/api/v1/storage/sessions/{id}/commit` | Verify and atomically publish |
| `DELETE` | `/api/v1/storage/sessions/{id}` | Cancel; drop partials, release reservation |
| `GET` | `/api/v1/storage/tree` | List a folder (paged) |
| `GET` | `/api/v1/storage/delta` | Changes since a cursor (doc 07 §3) |
| `GET` | `/api/v1/storage/files/{id}/content` | Download, `Range`-capable |
| `GET` | `/api/v1/storage/download/archive` | Stream a folder as ZIP64 |
| `POST` | `/api/v1/storage/folders` | Create an empty folder |
| `PATCH` | `/api/v1/storage/nodes/{id}` | Rename or move (within a bucket) |
| `POST` | `/api/v1/storage/nodes/move-bucket` | Move between buckets (doc 08 §5) |
| `DELETE` | `/api/v1/storage/nodes/{id}` | Delete file or folder (recursive) |

## 2. Conventions

- **Auth:** `Authorization: Bearer <checkpoint-token>` on every call, verified by
  `checkpoint.verify` per [01-server-authentication.md](01-server-authentication.md). No
  cookies, no CSRF for the API surface — it is token-authenticated and not
  cookie-authenticated, so CSRF does not apply. (The existing web UI's CSRF-token hunt,
  audit C12, disappears with it.)
  - `401 UNAUTHENTICATED` — missing, malformed, expired, or unverifiable token.
  - `401 TOKEN_EXPIRED` — distinguished from the above so a client can refresh and resume
    rather than treating the job as failed (doc 01 §7).
  - `403 NO_MEMBERSHIP` — token valid, but no `brain_memberships` record. Never a 500, and
    never a fallback to an empty reference num.
  - **Sessions belong to a member, not to a token.** A refreshed token for the same member
    resumes the same session; a different member's valid token gets `404` on that session
    id, not `403` (doc 12 §4).
- **Errors** are always JSON `{ "error": { "code": "...", "message": "...", ...context } }`.
  `code` is a stable machine string; `message` is human text and may be localised.
- **Sizes** are bytes in the API. The registry's `storage_files_size_kb` is kilobytes;
  conversion happens exactly once, in `QuotaService`, and is never done in a client.
- **Paths** on the wire are always POSIX `/`-separated, relative to the bucket root, no
  leading slash. A Windows client converts on the way out and on the way in.
- **Idempotency:** every write endpoint is safe to retry. `PUT …/files/{fileId}` with an
  already-satisfied range is a no-op `200`, not an append.

## 3. `GET /profile`

The single call the UI needs to render the storage picture.

```jsonc
{
  "member_reference_num": "abc1234",
  "plan": 3,                          // brain_memberships.membership, clamped
  "plan_label": "business",           // cosmetic ONLY — never used for logic
  "unlimited": false,
  "total_capacity_bytes": 67108864000,   // Σ tiers[0..3] — doc 08 §2
  "total_used_bytes": 1610612736,
  "warn_at_pct": 85,
  "buckets": [
    { "bucket": 0, "capacity_bytes": 536870912,   "used_bytes": 536870912,  "state": "active", "full": true,  "available": true },
    { "bucket": 1, "capacity_bytes": 2147483648,  "used_bytes": 1073741824, "state": "active", "full": false, "available": true },
    { "bucket": 2, "capacity_bytes": 10737418240, "used_bytes": 0,          "state": "active", "full": false, "available": true },
    { "bucket": 3, "capacity_bytes": 53687091200, "used_bytes": 0,          "state": "active", "full": false, "available": true },
    { "bucket": 4, "capacity_bytes": -1,          "used_bytes": 0,          "state": "locked", "full": false, "available": false }
  ],
  "next_write_bucket": 1,
  "retention": {                      // present only when a downgrade is pending
    "pending": false,
    "retired_buckets": [],
    "purge_after": null
  }
}
```

`available: false` means the member's plan does not reach that bucket. `state: "retired"`
means it did once and no longer does — read-only, awaiting purge (doc 08 §6).

## 4. `POST /scan` — the preflight

Validates names and reports conflicts **without** creating a session, reserving quota, or
touching the filesystem. Cheap, repeatable, and the thing a client calls while the member
is still looking at a file picker.

**Request**

```jsonc
{
  "bucket": null,                     // null = let the server route (doc 08 §3)
  "files": [
    { "rel_path": "start/a.txt",     "size": 1024 },
    { "rel_path": "start/sub/b.txt", "size": 2048 },
    { "rel_path": "start/bad\u0000name.txt", "size": 10 }
  ]
}
```

**Response `200`**

```jsonc
{
  "ok": false,
  "file_count": 3,
  "total_bytes": 3082,
  "target_bucket": 0,
  "quota": { "fits": true, "would_cascade_to": null, "used_after_bytes": 3082 },
  "rejected": [
    { "rel_path": "start/bad\u0000name.txt", "code": "NAME_CONTROL_CHAR",
      "message": "Contains a NUL byte", "segment": "bad\u0000name.txt" }
  ],
  "conflicts": [
    { "rel_path": "start/a.txt", "existing": { "size_bytes": 900, "sha256": "…", "modified_at": "…" },
      "incoming": { "size_bytes": 1024 }, "identical": false }
  ],
  "warnings": [
    { "rel_path": "start/README.md", "code": "CASE_COLLISION",
      "message": "Differs only by case from existing start/readme.md" }
  ]
}
```

`ok` is `true` only when `rejected` is empty. Conflicts and warnings do not make a scan
fail — they are decisions for the member (doc 07 §4).

**`identical: true`** means the incoming file's declared sha256 matches the stored one; the
client can offer "skip identical" and, at session open, the server will mark those files
`skipped` without transferring a byte. This is the single biggest win for re-syncing an
unchanged folder.

## 5. Upload

### 5.1 `POST /sessions`

**Request**

```jsonc
{
  "bucket": null,
  "conflict_policy": "fail",          // fail | skip | replace | keep_both
  "origin": "desktop",
  "files": [
    { "rel_path": "start/a.txt", "size": 1024, "sha256": "…", "client_modified_at": "2026-08-20T11:02:00Z" }
  ]
}
```

**Response `201`**

```jsonc
{
  "session_id": "9f3c…",
  "bucket": 0,
  "expires_at": "2026-08-24T10:00:00Z",
  "chunk_size_hint": 8388608,
  "max_parallel_hint": 4,
  "files": [
    { "file_id": "b71e…", "rel_path": "start/a.txt", "offset": 0, "state": "pending" }
  ],
  "skipped":  [ { "rel_path": "start/unchanged.bin", "reason": "IDENTICAL" } ],
  "rejected": [],
  "conflicts": []
}
```

**Failure modes**

| Status | `code` | When |
|---|---|---|
| `409` | `CONFLICTS_UNRESOLVED` | `conflict_policy: "fail"` and conflicts exist. Body carries the full conflict list. **This is G5** — never a silent overwrite. |
| `422` | `MANIFEST_REJECTED` | One or more names are illegal and the client did not remove them |
| `413` | `QUOTA_EXCEEDED` | Does not fit in any bucket the plan allows. Body carries `required_bytes`, `available_bytes`, `plan`, `upgrade_unlocks_bytes` |
| `423` | `BUCKET_RETIRED` | Target bucket is in retention (doc 08 §6) — read-only |

### 5.2 `PUT /sessions/{id}/files/{fileId}`

```
Content-Range: bytes 0-8388607/104857600
Content-Type: application/octet-stream
<raw bytes>
```

| Status | Meaning |
|---|---|
| `200` | Accepted. `{ "offset": 8388608 }` — the new authoritative offset |
| `409` | Range start ≠ current `.part` length. `{ "code": "OFFSET_MISMATCH", "expected_offset": N }`. Client re-seeks to `N`; it does **not** restart |
| `413` | This chunk would exceed the session reservation |
| `410` | Session expired or cancelled |

The body is streamed to the `.part` file; the server never buffers a whole chunk in memory
(acceptance A8).

### 5.3 `PUT /sessions/{id}/batch` — small-file coalescing

10 000 × 20 KB as 10 000 requests is ~10 000 round trips and dominates wall clock. Files
below **1 MiB** are packed into one length-prefixed frame:

```
repeat:
  u16  fileIdLen          (big-endian)
  u8[] fileId             (ASCII UUID)
  u64  contentLen         (big-endian)
  u8[] content
```

A batch is capped at **64 MiB or 512 files**, whichever comes first. That cap is also its
resume granularity — a failed batch re-sends the whole frame, and re-sending ≤ 64 MiB is
the price of turning 10 000 round trips into ~40 (acceptance A7).

Response `200`: `{ "accepted": ["fileId", …], "failed": [ { "file_id": "…", "code": "…" } ] }`.

### 5.4 Chunking and parallelism

- Chunk size adapts between **4 MiB and 32 MiB**. Start at the server's
  `chunk_size_hint`, grow while throughput improves, halve on a stall or timeout.
- **4 parallel streams** by default, adaptive 1–8. Concurrency rises only while *aggregate*
  throughput rises.
- Chunk size governs resume granularity and progress cadence. It is **not** a read size —
  the client streams from a seeked file handle and lets the OS batch syscalls.
- Progress is reported to the UI at **≤ 4 events/sec per job**, aggregated across streams.
  Per-chunk UI updates are what make a transfer feel slower than it is.

### 5.5 `POST /sessions/{id}/commit`

For each file in order:

1. `.part` length **must** equal `declared_size` — else `failed`, reason `SIZE_MISMATCH`.
2. If `declared_sha256` was supplied, the digest of the `.part` must match — else
   `failed`, reason `DIGEST_MISMATCH`. (Digest is computed streaming, during upload, not
   as a second read pass.)
3. `PathResolver` produces the final absolute path from `rel_path`, re-validating and
   re-asserting containment — **the validation done at session open is not trusted here**,
   because a symlink could have been planted in between (doc 12 §3).
4. `rename(2)` the `.part` into place. Atomic.
5. In one DB transaction: insert/update the `storage_nodes` row, convert the reservation
   from `reserved_bytes` to `used_bytes`.

Nothing appears at its final path before step 4, so a failed verification can never publish
a corrupt file.

**Response `200`**

```jsonc
{
  "committed": [ { "file_id": "…", "rel_path": "start/a.txt", "node_id": 4821, "etag": "…" } ],
  "failed":    [ { "file_id": "…", "rel_path": "start/c.bin", "code": "DIGEST_MISMATCH" } ],
  "bucket_usage": { "bucket": 0, "used_bytes": 536871936, "capacity_bytes": 536870912 }
}
```

A partial commit is a normal outcome, not an error: successes are published, failures stay
in the session for a retry that re-sends only those files.

## 6. Browse and delta

### 6.1 `GET /tree`

`?bucket=0&path=start&cursor=&limit=200`

```jsonc
{
  "path": "start",
  "bucket": 0,
  "entries": [
    { "id": 4821, "kind": "folder", "name": "sub",   "rel_path": "start/sub", "child_count": 12 },
    { "id": 4822, "kind": "file",   "name": "a.txt", "rel_path": "start/a.txt",
      "size_bytes": 1024, "sha256": "…", "mime_type": "text/plain",
      "etag": "…", "modified_at": "…", "client_modified_at": "…" }
  ],
  "next_cursor": "eyJpZCI6NDgyMn0",
  "total": 1342
}
```

Paged from the first response. A folder with 100 000 entries must not be a 100 000-row JSON
document, and no client should assume it can hold a whole tree in memory.

### 6.2 `GET /delta`

`?cursor=<opaque>&bucket=` — the sync primitive. Full contract in
[07-sync-and-dedupe.md](07-sync-and-dedupe.md) §3.

```jsonc
{
  "changes": [
    { "op": "upsert", "node": { … } },
    { "op": "delete", "id": 4820, "rel_path": "start/old.txt", "deleted_at": "…" }
  ],
  "next_cursor": "…",
  "has_more": true,
  "reset_required": false
}
```

`reset_required: true` tells the client its cursor is too old (beyond tombstone retention)
and it must do a full tree walk. Stating this explicitly is what keeps a stale client from
silently missing deletions forever.

## 7. Download

### 7.1 `GET /files/{id}/content`

Honours `Range`, returns `Accept-Ranges: bytes`, `ETag`, `Content-Length`,
`Content-Disposition: attachment; filename*=UTF-8''<pct-encoded>`.

`filename*` (RFC 5987) rather than plain `filename=` because real filenames contain
spaces, quotes, and non-ASCII — the whole point of G1 — and a plain `filename=` mangles
them at the last step.

A `206` response to a `Range` request is what gives every client resumable download with
no extra protocol.

### 7.2 `GET /download/archive`

`?bucket=0&path=start` → streamed ZIP64. Never staged to disk. **ZIP64 is mandatory**, not
optional: a 50 GB bucket exceeds ZIP32's 4 GB limits, and a plain ZIP writer will silently
produce a corrupt archive at that size.

## 8. Mutations

| Endpoint | Notes |
|---|---|
| `POST /folders` | `{bucket, rel_path}`. Creates an empty folder — representable now that folders are rows (doc 04 §2). `409 ALREADY_EXISTS` on collision |
| `PATCH /nodes/{id}` | `{name}` to rename, `{parent_id}` to move within a bucket. Folder rename rewrites descendants' `rel_path` in one statement, in a transaction |
| `POST /nodes/move-bucket` | `{node_ids[], target_bucket}` — doc 08 §5. `403` if the plan does not reach the target, `413` if it does not fit, `423` if retired |
| `DELETE /nodes/{id}` | Recursive for folders. Soft-deletes rows (tombstones for delta), unlinks from disk, decrements the ledger — all in one transaction |

## 9. Rate and abuse limits

| Limit | Value | Why |
|---|---|---|
| Open sessions per member | 5 | Each holds a quota reservation |
| Manifest entries per session | 50 000 | Beyond this the client splits; keeps the scan bounded |
| Session TTL | 48 h | Bounds a leaked reservation (doc 04 §3.2) |
| `POST /scan` | 30/min | It is cheap but not free, and it is unauthenticated-adjacent work |
| `PUT` chunk size | ≤ 64 MiB | Bounds per-connection server work |
| Concurrent uploads per member | 8 streams | Matches `max_parallel_hint` |

## 10. Versioning

The prefix is `/api/v1/storage`. A breaking change to the wire format ships as `/v2` beside
it; clients advertise what they speak via `X-AFS-Storage-Protocol: 1`. Desktop and mobile
release on their own cadence and **will** lag the server, so the server must not assume the
newest client. Any response field added later is additive and optional.
