# 12 — Security

## Completion document

When this plan step is completed, write `complete/12-security.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. Every check in this
document is implemented with a comment naming the threat it defends against — a check
whose purpose is not written down gets "simplified" away in a later refactor.

---

> **Extended by [storage-sharding 08](../storage-sharding/08-security.md).** This document's bar
> ("no request can cause a read or write outside the authenticated member's own storage root")
> still holds and storage-sharding states it again explicitly. Sharding adds trust boundaries
> this document does not cover at all: the storage device as a separate trust domain outside the
> shared database (D3), per-server signed credentials, and asymmetric upload/download tokens
> (device holds only a public key). The traversal corpus and `NameValidator` below are ported
> **verbatim** into the storage device app, not reimplemented — see `storage-sharding/05` §5.

## 1. Threat model

**In scope:** a member — authenticated, legitimate, and possibly hostile — attempting to
read, write, or destroy data belonging to another member or to the host; and an
unauthenticated party attempting the same.

**Out of scope:** an attacker with shell access on the storage host, a malicious AFS
operator, and physical access. Those are outside what application code can defend.

The bar: **no request, from any client, can cause a read or write outside the
authenticated member's own storage root — regardless of what strings it contains.**

## 2. Authentication — see step 01

**Authentication is not designed in this document.** It is
[01-server-authentication.md](01-server-authentication.md), it is step 1 of this plan, and
storage is one consumer of it among several.

What storage depends on, and must not re-implement:

| Guarantee | Source |
|---|---|
| Every storage route is inside `checkpoint.verify` | doc 01 §5.1 |
| `member_reference_num` is set from a verified token, never from the request | doc 01 §5.2 |
| `member_plan` (0–4, clamped, missing ⇒ 0) is set the same way | doc 01 §5.2 |
| A valid token with no membership record is rejected 403 at the middleware | doc 01 §2.3.2 |
| Auth unreachable ⇒ 401/403, never a pass-through | doc 01 §4.1 |
| Secondary users resolve to the primary member's reference num | doc 01 §2.3.3, §6 |

Audit defect S1 — `logInAsUserByUsername("appfactorystudio")` at
`StorageController.php:35`, which makes every caller the same admin — is closed by deleting
that controller (doc 09 §4) and by step 01 bringing the routes inside the group.

### 2.1 No implicit tenancy anywhere

This part *is* storage's own rule.

No Eloquent global scope, no request-lifecycle singleton holding "the current member", no
middleware-set static. Every repository method takes `member_reference_num` as its **first
required parameter** (doc 04 §7).

The reasoning is the audit itself: S1 happened because identity was resolved somewhere
invisible and then trusted everywhere. A global scope has the same shape — invisible at the
call site, and silently absent in raw queries, `DB::table()`, and queued jobs. A required
parameter cannot be silently absent; it fails at the call site, not in production.

## 3. Path containment

Every write destination and every read source is produced by `PathResolver` (doc 03 §3.1),
which performs, in order:

1. **Reject** absolute paths, drive letters (`C:`), UNC prefixes (`\\`, `//`).
2. **Validate** every segment against `NameValidator` (doc 06 §3) — including NUL,
   control characters, `..`, and length caps.
3. **Normalise** to NFC (doc 06 §4).
4. **Split with POSIX semantics.** Never `path.normalize`/`realpath` before splitting —
   doc 06 §7.3 documents the Windows bug where OS-native normalisation converted `/` to
   `\` and defeated the `..` check entirely.
5. **Join** under the member's bucket root.
6. **Assert containment:** `realpath(dirname($absolute))` must be inside
   `realpath($bucketRoot)`. This is the step that catches a symlink.

### 3.1 Symlinks

An attacker who can create a symlink inside their own bucket (via a synced client, or a
crafted archive) can point it at `/etc` or at another member's root. Then an upload
"inside their own bucket" writes wherever the link goes.

Defences, all three:

- **Containment is asserted at commit time, not only at session open.** A link planted
  between the two is caught at step 6, immediately before `rename(2)`.
- **The `.part` file never carries a member-supplied name.** Bytes accumulate at
  `.staging/{sessionId}/{fileId}.part`, so a hostile path has zero filesystem contact until
  it has passed validation.
- **Symlinks are never created by the storage service**, and a symlink encountered during
  a walk is skipped and reported — never followed.

### 3.2 The traversal corpus

Acceptance A2 requires every entry to be rejected, verified server-side, and the same
corpus feeds the client validators (doc 06 §7):

```
../escape.txt          ..\escape.txt         a/../../b
....//escape.txt       %2e%2e%2fescape       ..%2fescape
C:\evil.txt            \\?\C:\evil.txt       \\server\share\x
/etc/passwd            //etc/passwd          ./././../x
a\u0000b.txt           a/./../../b           <300-byte segment>
start/../../../../mnt/storage1/other/storage/0/x
```

`....//` is in the list specifically because it defeats naive `str_replace('..','')`
sanitisers of the kind currently in `StorageController.php:203` — which is why the design
**rejects** rather than **strips**. A stripping sanitiser can always be fed input that
becomes traversal after stripping; a rejecting validator cannot.

## 4. Authorisation on every object

Authentication answers *who*; every handler must still answer *whose*.

| Operation | Check |
|---|---|
| `GET /files/{id}/content` | `node.member_reference_num === caller` — **before** any disk access |
| `PATCH /nodes/{id}`, `DELETE /nodes/{id}` | Same, plus target parent ownership for a move |
| `GET /sessions/{id}`, `PUT`, `commit`, `cancel` | `session.member_reference_num === caller` |
| `POST /nodes/move-bucket` | Every node owned by caller **and** `target_bucket <= plan` |
| `GET /tree`, `/delta` | Scoped by `member_reference_num` in the query, never by a client-supplied filter |

A failed ownership check returns **404, not 403**. A 403 confirms the id exists and turns
sequential ids into a membership oracle.

Node ids are sequential (`bigint` primary keys), which makes enumeration trivial — so the
ownership check, not the unguessability of the id, is the whole defence. That is the
correct place to put it, but it means the check can never be skipped "because the id came
from our own UI".

## 5. Upload-specific risks

| Risk | Defence |
|---|---|
| **Quota bypass by lying about size** | The declared size reserves quota; the actual `.part` length is checked at commit. A file larger than declared is rejected `413` mid-stream once it exceeds the reservation |
| **Zip bomb via archive download** | Archives are only ever *generated*, never *extracted*, server-side. Nothing uploaded is decompressed by the server |
| **Storage exhaustion of the host** | Physical `bavail` check independent of plan quota; `507` when the volume is low. Bucket 4's `-1` means "no plan limit", not "infinite hardware" (doc 08 §8) |
| **Session flooding** | ≤ 5 open sessions/member, each with a 48 h TTL and a released reservation on expiry |
| **`.part` litter from abandoned sessions** | `storage:session-sweep` deletes staging dirs with no live session after 48 h |
| **Content-type spoofing** | MIME is stored for display only and is **never** used to decide execution. Nothing under `/mnt/storage1/…/storage/` is served by a web server or executed |
| **Filename-based log injection** | Control characters rejected in portable mode (doc 06 §3.2); all logging of member-supplied names is escaped regardless |
| **Slowloris via long-lived chunk uploads** | Per-request timeouts at nginx; a chunk that stalls beyond the timeout is dropped and the client resumes from the true offset |

## 6. Download-specific risks

- **`Content-Disposition` header injection.** Real filenames contain quotes, newlines, and
  non-ASCII. Always `filename*=UTF-8''<percent-encoded>` (RFC 5987) and never raw
  interpolation into the header (doc 05 §7.1).
- **`Content-Type` sniffing.** Serve `application/octet-stream` with
  `X-Content-Type-Options: nosniff` for downloads. A member-uploaded `.html` must never
  render in the members origin — that would be stored XSS against the member's own
  session.
- **Never serve storage from the application origin.** Downloads stream through the
  authenticated endpoint; the storage tree is not mapped to any web root, so there is no
  path by which a file becomes a URL.

## 7. Secrets and tokens

| Client | Token at rest |
|---|---|
| Web | In memory only. Never `localStorage` — an XSS anywhere in the members app would otherwise exfiltrate a long-lived storage token |
| Desktop | `safeStorage` (already wired, `src/main/index.ts:386`), main process only; the renderer never sees a token |
| Mobile | Keychain / Keystore via `flutter_secure_storage` or `checkpoint_client`'s own persistence |

Server-side: no storage secret exists. Authorisation derives entirely from the verified
Checkpoint token, so there is no shared key to leak or rotate.

Audit defect S2 — `env()` read at request time to build a raw PDO connection — is removed
with the controller. Credentials come from Laravel's configured connection, which also
fixes the `config:cache` breakage.

## 8. Rate limiting

| Endpoint class | Limit | Rationale |
|---|---|---|
| `POST /scan` | 30/min/member | Cheap but not free; bounds manifest-validation CPU |
| `POST /sessions` | 20/min/member | Each reserves quota |
| `PUT` chunks | Unlimited count, ≤ 8 concurrent, ≤ 64 MiB each | Throughput is the product; concurrency and size are the controls |
| `GET /tree`, `/delta` | 120/min/member | Sync polls this |
| Mutations | 60/min/member | |
| Failed auth | 10/min/IP, then backoff | |

Limits are per **member**, not per IP — mobile members share carrier NAT, and an IP limit
would throttle unrelated people together.

## 9. Audit logging

Logged with `member_reference_num`, timestamp, source IP, and client origin:

- Every rejected path, with the rule id that rejected it — **a spike here is an attack
  signal**, and it is the only way to notice one.
- Every ownership-check failure (the 404-not-403 cases).
- Every session open/commit/cancel with byte totals.
- Every retention state change (`storage_retention_events`, doc 04 §5) — deletion of member
  data must be explainable months later.
- Every bucket move.

Never logged: file **contents**, tokens, or full paths at debug level in production —
paths are member data.

## 10. Pre-launch checklist

| # | Item |
|---|---|
| 0 | **Step 01's checklist (AU1–AU12) passes.** Nothing below is meaningful without it |
| 1 | `grep -rn "/mnt/storage1" app/` returns exactly one match, in `PathResolver` |
| 2 | `grep -rn "logInAsUserByUsername" app/` returns zero matches |
| 3 | No storage route is registered outside the `checkpoint.verify` group |
| 4 | The §3.2 traversal corpus is rejected in full, server-side, with a test |
| 5 | The doc 06 fixture corpus passes in TypeScript, Dart, and PHP |
| 6 | Every handler taking an id has an ownership test proving it returns 404 for another member's id |
| 7 | Downloads set `nosniff` and RFC 5987 `filename*` |
| 8 | No token is written to `localStorage` in the web client |
| 9 | `nginx`: `proxy_request_buffering off` on the session upload location |
| 10 | Physical free-space check present and independent of plan quota |
| 11 | A penetration pass against a second member's ids, sessions, and node ids finds nothing readable |
