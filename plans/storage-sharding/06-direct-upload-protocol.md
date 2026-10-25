# 06 — Direct upload protocol

## Completion document

When this plan step is completed, write `complete/06-direct-upload-protocol.complete.md`.

---

The chunked, resumable protocol in [cloud-storage 05](../cloud-storage/05-api-contracts.md) does
not change shape. What changes is **who receives the bytes**. Members keeps every decision and
every database write; the device receives the chunks.

## 1. The flow

```
client                       members                          storage device N
  │  POST /sessions            │                                    │
  │ ─────────────────────────▶ │ validate, quota, bucket route,     │
  │                            │ PLACEMENT → device N, reserve      │
  │                            │  POST /internal/sessions (grant) ─▶│ record grant, mkdir .staging
  │                            │ ◀──────────────────── 201 ─────────│
  │ ◀── 201 {session_id, files, upload_url, upload_token, …} ────── │
  │                                                                  │
  │  GET  {upload_url}/v1/sessions/{id}          (offsets) ─────────▶│
  │  PUT  {upload_url}/v1/sessions/{id}/files/{fileId}  (chunks) ───▶│ stream to .part
  │                                                                  │
  │  POST /sessions/{id}/commit ▶ members                            │
  │                            │  POST /internal/sessions/{id}/commit ▶│ verify, rename(2)
  │                            │ ◀── per-file {size, sha256, mime, etag} │
  │                            │ write file rows, convert reservation, audit
  │ ◀── 200 {committed[], failed[], bucket_usage} ──                 │
```

The client-visible additions to the existing `201` from `POST /sessions` are `upload_url`
(`https://{N}.storage-device.{base_domain}`), `upload_token`, and `upload_token_expires_at`.
Everything else in the response is as today. A client written for cloud-storage that ignores
those fields would simply have no device to talk to — so the three clients change ([10](10-members-and-client-changes.md)),
but only in *where the range PUTs go*.

## 2. The grant (members → device)

`POST /internal/sessions`, signed, before the client is told anything:

```
{ session_id, device: N, member_reference_num, bucket, expires_at,
  files: [ { file_id, rel_path, size, sha256|null, client_modified_at } ] }
```

The device stores it and creates the staging directory. **A token without a grant is
worthless**, and a grant without a token is inert: the device will accept bytes only for a
`(session_id, file_id)` it holds a grant for, at the declared size, for the member and device the
grant names. If the grant call fails, members releases the reservation and returns `503
STORAGE_UNAVAILABLE` — the client never sees a session it cannot use.

## 3. Tokens

**Signed by members with an asymmetric key; devices hold only the public key** (D8). A device,
or an attacker who owns a whole storage server, can *verify* a token and can never *create*
one.

| Claim | Meaning |
|---|---|
| `kid` | Which members signing key; lets keys rotate with overlap |
| `sub` | `member_reference_num` |
| `dev` | Device number this token is valid for. **A device rejects a token whose `dev` is not its own** |
| `sid` | Session id (upload) — or `nid`, a node id (download) |
| `ops` | `["upload"]` or `["download"]` — never both |
| `iat`, `exp` | Short lifetime. Upload: 15 minutes, refreshable. Download: 5 minutes |
| `jti` | Unique id; the device records a spent `jti` where a token is single-use (download) |

**Corrections found while building step 05 (2026-09-26) — members' minter in P0 must match these:**

- **Format and algorithm are fixed: JWS compact, RS256.** `base64url(header).base64url(claims).base64url(signature)`,
  header `{"alg":"RS256","typ":"JWT","kid":"…"}`, signed with `openssl_sign(…, OPENSSL_ALGO_SHA256)`. The device
  never reads the algorithm from the token: `none`, HS256-with-the-public-key and every other algorithm are refused.
  RS256 rather than EdDSA because the PHP builds here have OpenSSL but not libsodium.
- **A download token must also carry `bkt` (int bucket) and `rel` (the relPath).** The device keeps no node table, so
  `nid` alone cannot say where the file is. `nid` must equal the URL's node id.
- **All of `iat`, `exp` are integers; `dev` an integer; `ops` an array of exactly one string; `sub` and `jti` non-empty
  strings.** Anything else is refused.
- **Only an expired token answers `TOKEN_EXPIRED`;** every other failure is a uniform `TOKEN_INVALID`.
- **OPEN QUESTION — single-use download.** "Single-use `jti`" and "resumable `Range` download" contradict each other:
  resuming means presenting the same token again. The device currently does **not** record spent `jti`s; the 5-minute
  lifetime and `dev`/`nid`/`rel` binding are the limits. Decide in P2: keep it (short-lived, multi-use), or mint a
  fresh token per `Range` request through members.
- **The grant (§2) gains `replace` per file** (see [05](05-storage-app.md) §9).

A token is sent as `Authorization: Bearer …`. The device checks signature, `kid`, `exp`, `dev`,
`ops` against the endpoint, and that the grant for `sid` exists and names the same `sub`.
Path or file id in the URL that does not belong to the grant is a `404`.

**Refresh.** A long upload outlives a 15-minute token. The client calls
`POST /sessions/{id}/upload-token` on members (which re-checks the Checkpoint bearer token, the
session, and its expiry) and gets a new token. A `401 TOKEN_EXPIRED` from a device is exactly the
signal cloud-storage already teaches clients to handle by refreshing and resuming from the
device's offsets — no failed job ([cloud-storage 01 §7](../cloud-storage/01-server-authentication.md)).

## 4. Resume

Offsets come **from the device** (`GET /v1/sessions/{id}`), the length of each `.part` on disk.
This is the existing rule — offsets are the server's truth — with "the server" now the device.
A `409 OFFSET_MISMATCH` carries the true offset and the client re-seeks, unchanged.

Members' own `GET /sessions/{id}` remains, for session state, and does **not** return offsets
any more; it returns the `upload_url` so a reconnecting client knows where to ask.

## 5. Commit and the device → members question

Members drives commit (the client calls members, members calls the device), so **the device
never needs to call members for correctness**. That keeps the device unable to write to the
members database by any route, and keeps commit a single request/response the client can retry.

If members crashes between the device's `rename(2)` and writing the rows, the files exist on
disk with no row. Two defences: the device's commit is **idempotent** (a repeated commit returns
the same result), and a members reconciliation pass — the `ReconcileStorage` command
cloud-storage listed as unbuilt ([cloud-storage 04 §6](../cloud-storage/04-database-schema.md)) — compares
device listings to rows. Both are noted as follow-up work, not phase 1.

## 6. Download

Q4 is resolved as **members returns a URL, not a redirect**:

```
GET /files/{nodeId}/download-url          (members, Checkpoint bearer)
→ { "url": "https://{N}.storage-device…/v1/files/{nodeId}/content", "token": "…", "expires_at": "…" }
```

Members checks ownership (404, never 403), reads the file row's `device`, and signs a
single-use download token with `dev = N`. The client then `GET`s the device with `Range` for
resumable download. A `302` from members would carry no `Authorization` header to a different
origin in several HTTP stacks; an explicit URL + token does not depend on that.

## 7. CORS

Devices allow the members origins (production, and the lab's) and no others, for
`GET/PUT/OPTIONS`, exposing `Content-Range`, `ETag`, `Accept-Ranges`. Desktop and mobile are
not browsers and ignore CORS; the web client needs it.

## 8. Failure behaviour

| Situation | Behaviour |
|---|---|
| No eligible device at session open | `503 STORAGE_UNAVAILABLE` from members; reservation never made |
| Grant call fails | Reservation released; `503` |
| Device down mid-upload | Client goes `stalled`, backoff 1 s → 60 s, resumes from the device's offsets on return — the existing behaviour |
| Device down at commit | `503` from members; session stays `open`; retried |
| Token expired mid-upload | Client refreshes via members, resumes |
| Device reports `offline` while sessions are open | Sessions stay valid; no new sessions placed; members shows the member a stalled upload, not a failed one |

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Signing key management in members, public key publication through the Brain registry, token mint + verify with cross-language test vectors | A token minted by members verifies on the storage app; a token for device 2 is rejected by device 1 |
| **P1** | Grant, direct upload, commit via members, on one device | A multi-chunk file uploaded direct commits and appears in the tree |
| **P2** | Token refresh; download URL + token | A > 15-minute upload completes; a download resumes after interruption |
| **P3** | Failure-mode matrix (§8) exercised in the lab | Each row behaves as written |

## As built (2026-09-26)

Repos touched (all uncommitted): **www** (Brain keys), **members** (grant/commit/tokens/download), **storage_device**
(vector tests only — its verifying side was step 05).

- **P0** — *Signing key management.* Members: `TokenMinter` (RS256), `storage:token-key-generate {kid}` (private key
  written 0600 and never printed; public key printed for the Brain), `storage:control-key-generate`. Brain: tables
  `brain_storage_token_keys` (public keys by `kid`) and `brain_storage_members_key` (the control master, encrypted);
  `StorageKeyRegistry`; staff API `GET /keys`, `POST /token-keys`, `POST /token-keys/{kid}/retire`,
  `PUT /members-key`, `POST /members-key/promote` (level 99); `StorageConfigBuilder` now publishes `token_keys`
  (active, plus a retired key for `token_key_grace_seconds`), `members_key` and `members_key_next` **derived per
  server**. Vectors: `signature-vectors.json` (generated by www), `token-vectors.json` (generated by members) — see below.
- **P1** — *Grant, direct upload, commit on one device.* `POST /sessions` runs placement, then sends the signed grant
  to the device **before answering**; a failed grant (or a token that cannot be minted) releases the reservation,
  marks the session `failed`, cancels any orphaned grant and returns `503 STORAGE_UNAVAILABLE`. The `201` gains
  `upload_url`, `upload_token`, `upload_token_expires_at`. `PUT` on members is `421 UPLOAD_ON_DEVICE`; `GET /sessions/{id}`
  returns `upload_url` and no offsets. Commit calls the device and writes node rows **only for what the device reports
  committed**, at the granted size, for files of this session.
- **P2** — *Refresh and download.* `POST /sessions/{id}/upload-token`; `GET /files/{id}/download-url` → `{url, token,
  expires_at}` (ownership 404, legacy file `409 NOT_ON_DEVICE`, purging bucket 423); `GET /files/{id}/content` on a
  device file is `421 DOWNLOAD_ON_DEVICE`.
- **P3** — *Failure matrix, exercised over real HTTP against a running storage_device app* (not in the lab): device
  down at open → 503, reservation released; down at commit → 503, session stays open, retry succeeds; expired token →
  `TOKEN_EXPIRED`, refresh, resume from the device's offset; wrong-device / wrong-operation / other-member tokens
  refused; forged control call refused; cancel removes the device's staging. **Not exercised in the lab:** device
  `offline` while sessions are open, a device disappearing mid-chunk (client `stalled` + backoff), the > 15 minute upload,
  and the browser/desktop/mobile clients (step 10).

Corrections found while building (each already applied above and in [08](08-security.md) §2):
1. **The control key is per server, derived from a master,** not one shared key.
2. **Only the device's own grant fixes the file ids;** members takes the ids from its session rows, so a device cannot
   write a node for a file it was never granted.
3. Members reaches a device at `https://{N}.storage-device.{base_domain}` — the same host the client uses. A lab/test
   override `STORAGE_DEVICE_CONNECT_TO` connects elsewhere while still sending the real hostname in `Host`.

Known gaps carried forward: a `replace` upload that lands on a **different device** (or over a legacy local file)
updates the node but leaves the old bytes where they were — logged as `storage.replace.orphaned_old_bytes`; cleaning
up needs step 10's cross-device operations. Members' own `StorageSessionSweep` still only releases reservations; the
device's hourly `storage:sweep-staging` removes its staging.
