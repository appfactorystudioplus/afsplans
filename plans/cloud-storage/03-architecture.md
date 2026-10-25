# 03 — Architecture

## Completion document

When this plan step is completed, write `complete/03-architecture.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (JSDoc/PHPDoc/`///`).

---

> **Superseded in part by [storage-sharding](../storage-sharding/README.md).** §2's on-disk
> layout and §3.1's "`grep /mnt/storage1` returns exactly one match" invariant describe the *local*
> storage mode only. With placement on (`storage-sharding/04-placement.md`, off by default —
> see `storage-sharding/10-members-and-client-changes.complete.md` §"Decisions to confirm"), a
> session's bytes go to a storage device chosen by `PlacementService`, never through this
> mount, and `PathResolver` keeps its local-disk methods only as that fallback — so the `grep`
> invariant no longer holds project-wide, only within the local-mode code path. §6's "one state
> machine, three ports" is unchanged in shape; only *where the range PUTs go* differs (web
> client done, desktop/mobile not yet — `storage-sharding/10` §2).

## 1. The shape of the system

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Web client  │  │ Desktop app  │  │  Mobile app  │
│ (members JS) │  │ afsdesktop5  │  │   Flutter    │
└──────┬───────┘  └──────┬───────┘  └──────┬───────┘
       │                 │                 │
       │  scan → manifest → ranged PUT → commit   (§4, doc 05)
       │                 │                 │
       └────────────┬────┴─────────────────┘
                    │  HTTPS + Checkpoint bearer token
        ┌───────────▼─────────────────────────────────┐
        │  members15.appfactory.studio                │
        │  ┌───────────────────────────────────────┐  │
        │  │ checkpoint.verify → member_reference_num│ │
        │  ├───────────────────────────────────────┤  │
        │  │ StorageSessionController  (upload)    │  │
        │  │ StorageTreeController     (browse)    │  │
        │  │ StorageBucketController   (retention) │  │
        │  ├───────────────────────────────────────┤  │
        │  │ PathResolver · NameValidator          │  │
        │  │ QuotaService  · UsageLedger           │  │
        │  └────────────┬──────────────┬───────────┘  │
        └───────────────│──────────────│──────────────┘
                        │              │
            ┌───────────▼──┐    ┌──────▼────────────────┐
            │  members DB  │    │ /mnt/storage1/{ref}/  │
            │  (authoritative│   │   storage/{0..4}/     │
            │   ledger, D4) │    │     <real paths>      │
            └───────────────┘    └───────────────────────┘
                        │
            ┌───────────▼───────────────┐
            │ auth: registry JSON (tiers)│  ← read-only, static, safe
            │ auth: alloc_v2 (reporting) │  ← mirror only, not enforcement
            └────────────────────────────┘
```

Two facts about this diagram carry most of the design:

1. **The server is the only authority.** Resume offsets, quota, conflict sets, and name
   legality are all decided server-side. Clients compute the same answers locally only to
   give fast feedback and to avoid wasting bytes — never to be believed.
2. **Enforcement reads the members DB; entitlement reads the registry JSON.** Neither
   reads Parse. Why is decision D4, and the reason is in
   [02-current-state-audit.md](02-current-state-audit.md) §4.

## 2. On-disk layout

```
/mnt/storage1/{member_reference_num}/
  storage/
    0/                        ← bucket 0, capacity tiers[0] = 512 MB
      start/
        a.txt                 ← real name, real path (G1)
        sub/b.txt
      Q4 Report (final).pdf   ← spaces and parens preserved
    1/                        ← bucket 1, capacity tiers[1] = 2 GB
    2/  3/  4/                ← created lazily, only when the plan unlocks them
    .staging/
      {sessionId}/
        {fileId}.part         ← opaque id, never a member-supplied name
        {fileId}.meta         ← declared size, sha256, received ranges
    .trash/
      {retiredAt}-{bucket}/   ← downgrade retirement holding area (doc 08 §6)
```

### 2.1 Rules that make this layout safe

- **`.staging/` and `.trash/` are siblings of the buckets, not children.** A member-supplied
  relPath can never address them, because relPaths resolve *inside* `storage/{n}/` and a
  leading `.` segment is legal in a filename but cannot escape the bucket root.
- **Bytes in flight never carry a member-supplied name.** A `.part` file is named by an
  opaque `fileId`. The real name is applied exactly once, at commit, after validation —
  so a hostile name has no filesystem contact until it has survived every check.
- **Commit is `rename(2)` within the same filesystem**, which is atomic. A partially
  written file is never visible at its final path.
- **Buckets share one filesystem.** This makes a bucket-to-bucket move (doc 08 §5) an
  atomic `rename`, not a copy — instant regardless of file size. If buckets are ever split
  across mounts this becomes a copy-then-delete and the move UI must gain a progress
  indicator; that dependency is called out in doc 08 §5.3.

### 2.2 Reconciling `member_reference_num` with `systemuser`

The platform's existing `/mnt/node1/` paths key on **`systemuser`**
(`/mnt/node1/${systemuser}/mailbox/…` in the dovecot template, and throughout
`afsserver/commands/commands.sh`). This plan is specified against
**`member_reference_num`**, per the requirement.

If those are the same value in practice, nothing to do. If they are not, one of two things
must happen before Phase 1 ships, and **this is an open question for the operator, not a
decision this plan can make**:

- **(a)** storage keys on `systemuser` and the API resolves `member_reference_num →
  systemuser` once per request (adds a lookup + a cache), or
- **(b)** storage keys on `member_reference_num` and lives beside the `systemuser` tree in
  the same parent, accepting two naming schemes under `/mnt/node1/`.

The plan assumes **(b)** because the requirement names the path explicitly. Flag it in the
Phase 0 checklist ([13](13-rollout-and-testing.md) §1).

## 3. Server components

All new code under `apps/members`. One responsibility each; none of them know about HTTP
except the controllers.

| Component | Path | Responsibility |
|---|---|---|
| `StorageSessionController` | `app/Http/Controllers/Storage/` | `POST /sessions`, `GET /sessions/{id}`, `PUT …/files/{fileId}`, `PUT …/batch`, `POST …/commit`, `DELETE /sessions/{id}` |
| `StorageTreeController` | `app/Http/Controllers/Storage/` | Browse, search, download, move, rename, delete, create-folder |
| `StorageBucketController` | `app/Http/Controllers/Storage/` | Bucket usage, the retention formula payload, bucket-to-bucket moves |
| `PathResolver` | `app/Services/Storage/` | **The only place a member string becomes a filesystem path.** Validates, joins, and asserts realpath containment. Everything else takes an already-resolved path. |
| `NameValidator` | `app/Services/Storage/` | The rule set of [06](06-name-validation.md). Pure, no I/O, unit-testable against the shared corpus. |
| `QuotaService` | `app/Services/Storage/` | Reads the registry tiers + the member's plan; answers "does N bytes fit, and in which bucket". |
| `UsageLedger` | `app/Services/Storage/` | Transactional byte accounting; the authoritative number (D4). |
| `SessionStore` | `app/Services/Storage/` | Session lifecycle, `.part`/`.meta` bookkeeping, expiry sweep |
| `RetentionService` | `app/Services/Storage/` | Downgrade detection, retirement, grace window, deletion |
| `ReconcileStorage` | `app/Console/Commands/` | Scheduled ledger↔disk drift check (A13) |

### 3.1 The one rule about `PathResolver`

Every filesystem operation in the storage subsystem takes its path from `PathResolver`.
No controller, service, or job constructs a path by string concatenation. This is a single
choke point deliberately, because it is the only structural defence against S1/S4 class
bugs recurring — a reviewer can verify the property by grepping for `/mnt/storage1` and
finding exactly one match.

## 4. The upload flow, end to end

```
client                                      server
  │
  │ 1. LOCAL SCAN (doc 06)                  │
  │    walk selection, build manifest,      │
  │    flag illegal names before any I/O    │
  │                                         │
  │ 2. POST /sessions {manifest, policy} ──▶│ validate every relPath (authoritative)
  │                                         │ quota check → route to bucket (doc 08 §3)
  │                                         │ conflict scan against existing tree
  │ ◀── {sessionId, files[{fileId,offset}], │ create .part files
  │      conflicts[], rejected[], bucket}   │
  │                                         │
  │ 3. member resolves conflicts (doc 07 §4)│
  │    once, for the session, before bytes  │
  │                                         │
  │ 4. PUT …/files/{id} Content-Range ─────▶│ append at offset; 409 + true offset on mismatch
  │    PUT …/batch (small files) ──────────▶│ unpack length-prefixed frame
  │    …in parallel, N streams              │
  │                                         │
  │ 5. POST …/commit ─────────────────────▶ │ verify size + sha256
  │                                         │ PathResolver → final path
  │                                         │ rename(2) .part → final  (atomic)
  │                                         │ UsageLedger.add() in the same DB txn
  │ ◀── {committed[], failed[]}             │ index rows written
  │
  │ on reconnect: GET /sessions/{id} ──────▶│ offsets = length of each .part on disk
```

**Why the offset is the `.part` length and not a client counter:** there is exactly one
place resume state can be wrong, and it is the same place the bytes are. A client that
believes it sent more than it did gets a `409` carrying the true offset and re-seeks. This
is the same mechanism `afsdesktop5/src/main/sync/` uses and it is why that engine's
resume is byte-exact.

## 5. The download flow

Downloads are ordinary HTTP `GET` with `Range` support, which gives resume for free in
every client and lets the browser stream large files without buffering.

- `GET /api/v1/storage/files/{id}/content` → `Accept-Ranges: bytes`, honours `Range`.
- Folder download → server streams a ZIP built on the fly (`ZipStream`), never staged to
  disk, so peak memory is one buffer regardless of folder size. ZIP64 is required — a
  50 GB bucket exceeds the 4 GB ZIP32 limit.
- Desktop and mobile do **not** use the ZIP path for sync; they walk the tree and fetch
  files individually so that resume and progress are per file.

## 6. Client architecture — one state machine, three ports

All three clients implement the same six states. This is deliberate: the protocol carries
the complexity so each port stays thin.

```
        ┌─────────┐
        │ scanning│  local walk + name validation
        └────┬────┘
             ▼
        ┌─────────┐
        │preflight│  POST /sessions → conflicts, rejects, bucket, quota verdict
        └────┬────┘
             ▼
        ┌─────────┐  member answers conflicts (or policy pre-set)
        │ awaiting│
        └────┬────┘
             ▼
   ┌─────────────────┐        network drop / app backgrounded
   │    uploading    │◀──────────────────┐
   └────┬────────────┘                   │
        │                         ┌──────┴──────┐
        │                         │   stalled   │ backoff 1s→60s, jittered
        │                         └──────┬──────┘
        ▼                                │
   ┌─────────┐  POST /commit             │
   │verifying│───────────────────────────┘
   └────┬────┘
        ▼
   ┌─────────┐
   │  done   │  or failed(list of per-file reasons)
   └─────────┘
```

| Client | Where the engine lives | Where the state survives |
|---|---|---|
| **Web** | `resources/js/storage/UploadEngine.ts` | IndexedDB — so a tab reload resumes rather than restarts |
| **Desktop** | `src/main/cloud/` (main process) | The existing ledger pattern from `src/main/sync/ledger.ts` |
| **Mobile** | `lib/repositories/storage_repository.dart` | `sqflite` |

**The desktop client is a port, not a new build.** `afsdesktop5/src/main/sync/` already
contains this state machine — `jobs.ts` (registry, throttled progress, auto-resume),
`client.ts` (keep-alive agent, ranged PUT, 409 re-seek), `ledger.ts` (durable session
state), `paths.ts` (the validator). The cloud client swaps the transport target from a LAN
peer to `members15.appfactory.studio` and swaps the auth header from a pairing token to a
Checkpoint bearer token. Doc [10](10-desktop-client.md) §2 specifies what is shared versus
forked.

## 7. Identity chain

Built and specified in [01-server-authentication.md](01-server-authentication.md). Storage
consumes it; it does not re-implement any part of it.

```
Checkpoint SSO login (browser / desktop / mobile)  →  bearer token
       │
       ▼
Authorization: Bearer <token>          (every storage request)
       │
       ▼
checkpoint.verify middleware  (doc 01 §4.2)
  → CheckpointTokenVerifier::verify()          cached, fail-closed
  → POST auth15…/checkpoint/auth/token/verify  server-to-server
  → rejects when data.valid !== true           (200-on-failure trap, doc 01 §2.3.1)
  → rejects when member_reference_num is empty (null-member trap, doc 01 §2.3.2)
       │
       ▼
request attributes — the ONLY identity source a handler may read
  ├─ member_reference_num  ──▶ PathResolver: root = /mnt/storage1/{ref}/storage
  ├─ member_plan (0–4)     ──▶ QuotaService: bucket entitlement (doc 08 §2.4)
  ├─ checkpoint_user_id    ──▶ audit columns / log lines
  └─ is_secondary_user     ──▶ audit only; storage is member-scoped (doc 01 §6)
```

Three properties are load-bearing for storage specifically:

- **`member_plan` arrives with the request**, clamped 0–4 by the middleware, so
  `QuotaService` never re-queries `brain_memberships` and the token and the quota decision
  can never disagree.
- **A member-less token is rejected at the middleware**, so no handler can ever be reached
  with an empty reference num — which would otherwise resolve to `/mnt/storage1//storage`, a
  path shared by every such caller.
- **Secondary users resolve to the primary member's reference num** (doc 01 §2.3.3), and
  therefore to the primary member's buckets. That is the intended default and its
  consequences are recorded in doc 01 §6.

### 7.1 Sessions outlive tokens

An access token lives one hour; a 50 GB upload does not fit in one. Upload sessions are
bound to `member_reference_num`, **never to a token**, so a session survives any number of
token refreshes. A chunk `PUT` with an expired token returns `401 TOKEN_EXPIRED` and
pauses the job rather than failing it. Full design in doc 01 §7.

## 8. Concurrency model

| Concern | Mechanism |
|---|---|
| Two clients uploading the same relPath | DB unique index `(member_reference_num, bucket, rel_path)`; the loser gets a conflict response, not a corrupt file (A5) |
| Two chunks for the same `fileId` | `.part` append is guarded by an advisory lock keyed on `fileId`; a mismatched `Content-Range` start is rejected `409` |
| Quota race — two uploads that each fit but not together | `QuotaService` reserves bytes against the ledger **inside the session transaction**, released on cancel/expiry. See [04](04-database-schema.md) §4 |
| Commit vs delete of the same file | Row-level lock on the index row; commit re-checks the session is still active |
| Session abandoned mid-flight | `SessionStore` expiry sweep after 48 h releases the quota reservation and deletes `.part` files |

## 9. What each repo owns

| Repo | Owns | Must not |
|---|---|---|
| `apps/members` | The API, the ledger, the buckets on disk, the web client | — |
| `apps/auth` | `storage_files_size_kb.tiers[]` | **This plan proposes no edit to the registry.** The resource already exists and is correct. |
| `afsdesktop5` | Desktop client + its local index | Hold quota logic — it asks, it does not decide |
| `afsmobile_flutter` | Mobile client + its local index | Same |
| `afsserver` | The `/mnt/storage1` filesystem, backups, this plan | — |
