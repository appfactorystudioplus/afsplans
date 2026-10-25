# 07 — Sync, deduplication, and conflicts

## Completion document

When this plan step is completed, write `complete/07-sync-and-dedupe.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. The reconciler's
decision table (§2.3) must appear as a comment above the function that implements it.

---

## 1. What "sync" means here

Upload is not sync. Sync is: a member changes something on one side, and the other side
converges — including deletions, renames, and the case where both sides changed.

Scope for this plan:

| In scope | Out of scope |
|---|---|
| Two-way sync of a member-chosen local folder ↔ one bucket path | Real-time push (no websockets; polling + on-demand) |
| Add / modify / delete / rename detection on both sides | Multi-user concurrent editing of one file |
| Explicit conflict surfacing | Automatic three-way content merge |
| Delete propagation with tombstones | Version history / undelete beyond the trash window |
| Selective sync (choose subfolders) | Block-level delta within a file (Phase 5) |

**The web client does not sync.** A browser has no persistent local folder to sync
against. It uploads, browses, and downloads. Sync is a desktop and mobile capability, and
the API supports both modes from the same endpoints.

## 2. The three-state model

Every synced path is compared across **three** states, not two:

```
   LOCAL              BASE                REMOTE
 (disk now)     (last synced snapshot)  (server now)
      │                  │                   │
      └──────────┬───────┴─────────┬─────────┘
                 ▼                 ▼
            what changed      what changed
             locally           remotely
                 └────────┬────────┘
                          ▼
                    decision table §2.3
```

Two-state comparison (local vs remote) cannot tell "I deleted this file" from "the other
device added it" — both look like "present on one side only". Every sync tool that gets
deletion wrong gets it wrong here. `BASE` is what removes the ambiguity.

### 2.1 Where BASE lives

| Client | Store |
|---|---|
| Desktop | SQLite in the app's data dir, same pattern as `src/main/sync/ledger.ts` |
| Mobile | `sqflite` |

Columns: `rel_path`, `size`, `mtime`, `content_sha256`, `remote_etag`, `remote_version`,
`synced_at`.

### 2.2 Change detection without hashing everything

Hashing a 500 GB tree on every scan is not viable. Cheap detection first:

1. **Local:** `size` or `mtime` differs from BASE ⇒ *candidate*. Only candidates get
   hashed. Unchanged files cost one `stat`.
2. **Remote:** `etag` differs from BASE's `remote_etag` ⇒ changed. One `GET /delta` call
   covers the whole tree.
3. Hash is confirmed only for candidates, in a worker/isolate so the UI never blocks.

`mtime` alone is not trusted for equality — some tools preserve it across content changes —
which is why a candidate is confirmed by hash before being called modified. But an
unchanged `mtime` *and* size is trusted as unchanged, because the alternative is hashing
everything, forever.

### 2.3 The decision table

`L` = local vs BASE, `R` = remote vs BASE. `=` unchanged, `Δ` modified, `+` added,
`−` deleted.

| L | R | Action |
|---|---|---|
| `=` | `=` | Nothing |
| `Δ` | `=` | Upload local → remote |
| `=` | `Δ` | Download remote → local |
| `+` | — | Upload (new file) |
| — | `+` | Download (new file) |
| `−` | `=` | Delete remote, tombstone BASE |
| `=` | `−` | Delete local, tombstone BASE |
| `Δ` | `Δ` | **Conflict** — both changed (§4.2) |
| `Δ` | `−` | **Conflict** — modified locally, deleted remotely. Default: keep local, re-upload |
| `−` | `Δ` | **Conflict** — deleted locally, modified remotely. Default: keep remote, re-download |
| `−` | `−` | Both deleted — drop from BASE, no action |
| `+` | `+` | Same path added on both sides. Identical hash ⇒ adopt, no transfer. Different ⇒ **conflict** |

The three "keep X" defaults are **deliberately biased toward not losing data**: when a
modification races a deletion, the modification wins, because the member can always delete
again but cannot un-delete.

### 2.4 Rename detection

A rename looks like delete+add, and re-uploading a 4 GB file because it was renamed is a
bad outcome.

Detection: within one sync pass, a `−` and a `+` whose `content_sha256` **and** `size`
match are treated as a **move**. The client issues `PATCH /nodes/{id}` instead of an upload,
and no bytes cross the network.

This is heuristic and can be wrong when a file is deleted and an identical copy is created
elsewhere in the same pass — but the outcome in that case is still correct, just described
differently. It is only applied within a single pass, never across passes, to bound the
window.

## 3. The delta cursor

`GET /api/v1/storage/delta?cursor=…&bucket=…`

The cursor encodes `(last_seen_updated_at, last_seen_id)` — a compound key, not a bare
timestamp, because many rows can share a timestamp and a timestamp-only cursor either skips
or repeats rows at the boundary.

```jsonc
{
  "changes": [
    { "op": "upsert", "node": { "id": 4821, "rel_path": "start/a.txt", "etag": "…", "size_bytes": 1024, "content_sha256": "…", "version": 3 } },
    { "op": "delete", "id": 4820, "rel_path": "start/old.txt", "deleted_at": "2026-08-21T09:00:00Z" }
  ],
  "next_cursor": "eyJ0IjoiMjAyNi0wOC0yMVQwOTowMDowMFoiLCJpIjo0ODIxfQ",
  "has_more": false,
  "reset_required": false
}
```

### 3.1 Tombstones and `reset_required`

Deletions are reported from soft-deleted rows. Tombstones are retained **90 days**, then
hard-deleted.

A client whose cursor is older than the tombstone horizon would silently miss deletions —
files would resurrect on that device forever. So the server compares the cursor against the
horizon and returns `reset_required: true`, and the client discards BASE and does a full
walk.

Making this an explicit protocol field rather than an implicit assumption is the difference
between a sync tool that self-heals and one that quietly rots.

## 4. Duplicate prevention

The requirement: *prevent duplicate files and folders uploaded to the same directory so
that it's not a silent error.* Three independent mechanisms, at three layers.

### 4.1 Layer 1 — the database constraint

`UNIQUE (member_reference_num, bucket, rel_path_hash)` (doc 04 §2).

Structural, not advisory. Two concurrent uploads racing the same path cannot both succeed
regardless of application-layer checks; the loser gets a constraint violation which
`SessionStore` translates into a conflict response. **This is acceptance A5.**

### 4.2 Layer 2 — conflict detection at manifest time

Every conflict is found and reported **before the first byte moves** — the whole set, at
once, from `POST /scan` or `POST /sessions`.

Nothing is decided mid-stream. The failure mode this avoids is the one in audit defect
C10-adjacent territory: the old design held an HTTP request open per collision waiting for
a click, per file, mid-session.

Policies, chosen once per session:

| `conflict_policy` | Behaviour |
|---|---|
| `fail` (default) | Session open returns `409 CONFLICTS_UNRESOLVED` with the full list. Nothing happens until the member decides |
| `skip` | Colliding files are marked `skipped`, not transferred. Non-colliding files proceed |
| `replace` | Existing node is versioned and replaced on commit. Old bytes go to `.trash/` for the trash window |
| `keep_both` | Incoming file is renamed ` (2)`, ` (3)`, … before the extension |

`fail` is the default so that a client which forgets to pass a policy gets a loud stop, not
a silent overwrite.

### 4.3 Layer 3 — content identity

`content_sha256` on every node. Two independent uses:

- **Skip-identical.** At scan time, a manifest entry whose declared sha256 equals the
  stored one is reported `identical: true` and marked `skipped` at session open — **zero
  bytes transferred**. Re-syncing an unchanged 100 GB folder costs one manifest exchange.
- **True-duplicate reporting.** The `storage_nodes_dedupe` index finds same-content files
  at different paths, surfaced in the UI as "you have 4 copies of this file" with a
  member-driven cleanup. Never automatic — identical content at two paths is often
  deliberate.

Hashes are computed **streaming, during upload**, never as a second read pass over the
file.

### 4.4 Folder duplicates

A folder is a row with a unique `rel_path_hash` (doc 04 §2), so `POST /folders` on an
existing path returns `409 ALREADY_EXISTS` rather than silently succeeding.

Uploading folder `start/` twice does **not** conflict at the folder level — the folder is
merged, and conflicts are reported per *file* inside it. That matches the member's
intent ("add these files to that folder") while still catching every file-level collision.

## 5. Sync scheduling

| Trigger | Desktop | Mobile |
|---|---|---|
| App start | Full delta + local scan | Full delta + local scan |
| Local change | Filesystem watcher, 2 s debounce | Manual / on foreground — mobile OSes do not give reliable background FS events |
| Remote change | Poll `/delta` every 60 s while the view is open, 15 min in background | On foreground, and on pull-to-refresh |
| Manual | "Sync now" | Pull-to-refresh |

**No websockets in Phase 1.** Polling `/delta` is one indexed query returning an empty
change list in the common case. A push channel is a Phase 5 optimisation whose cost
(connection management, auth refresh, fan-out) is not justified until polling measurably
hurts.

### 5.1 Mobile background reality

Neither iOS nor Android permits arbitrary long-running background uploads. Both platforms
suspend or kill a backgrounded app, and iOS in particular will not run a foreground-style
HTTP client once the app is not visible.

Consequences, designed for rather than discovered later:

- Uploads use the platform background-transfer facility where available
  (`URLSession` background config on iOS, `WorkManager` on Android) and otherwise pause.
- A paused upload is **resumable by construction** — the session's offsets live on the
  server, so resuming after a kill is one `GET /sessions/{id}`.
- The UI never claims a background upload will complete while the app is closed. It says
  what is true: it resumes when the app is opened.

Details in [11-mobile-client.md](11-mobile-client.md) §6.

## 6. Selective sync

A member with a 50 GB bucket and a 256 GB laptop cannot mirror everything.

- Sync is configured per **folder pair**: a local directory ↔ a bucket path.
- Subfolders can be excluded; excluded paths are skipped in both directions and their BASE
  rows dropped.
- **Un-excluding does not delete anything** — it downloads. Exclusion is a view, not a
  deletion, and the UI says so, because "stop syncing this folder" is the most dangerous
  ambiguous phrase in any sync product.

## 7. Failure semantics

| Failure | Behaviour |
|---|---|
| Network drop mid-upload | Session survives. Reconnect → `GET /sessions/{id}` → resume at the true offset |
| Server 5xx | Exponential backoff 1 s → 60 s, jittered. Job is `stalled`, not `failed` |
| 30 s without bytes | `stalled`, keep retrying in background |
| Retry budget exhausted | `failed`, per-file reasons retained, "Retry failed" re-sends only those files |
| Local file vanished mid-upload | That file `failed` with `SOURCE_GONE`; the session continues |
| Local file changed mid-upload | Detected at commit by size/digest mismatch; file `failed` with `SOURCE_CHANGED` and is re-queued next pass |
| Quota hit mid-session | Remaining files `failed` with `QUOTA_EXCEEDED`; already-committed files stay. UI offers upgrade or bucket move |
| Clock skew between client and server | All ordering uses server `version`/`etag`, never client timestamps. `client_modified_at` is stored for display only |

**Stalled is not failed.** Most "failed" transfers in practice are a laptop lid closing or
a phone changing networks. Treating those as terminal produces a product that appears to
lose data it has not lost.
