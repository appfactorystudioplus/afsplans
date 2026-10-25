# 08 — Security

## Completion document

When this plan step is completed, write `complete/08-security.complete.md`.

---

Builds on [cloud-storage 12](../cloud-storage/12-security.md). That document's bar still holds:
**no request, from any client, can cause a read or write outside the authenticated member's own
storage root.** Sharding adds trust boundaries; this step names them.

## 1. Boundaries

```
 client ──token──▶ storage device          (bytes; token-authenticated)
 members ──signed─▶ storage app  /internal (control; HMAC, per-server credential)
 storage app ──signed──▶ Brain             (config pull, reports; HMAC, per-server credential)
 Brain ──signed──▶ storage app  brain-app/ (refresh, state; HMAC)
 members ──reads──▶ shared DB ◀──writes── Brain      (the only database, and never the storage app)
```

The storage app sits **outside** the shared-database trust domain (D3). It holds no members
data beyond what a grant carries and no database credential for the shared DB.

## 2. Credentials

| Path | Credential | Notes |
|---|---|---|
| Storage app → Brain, Brain → storage app | **Per-server secret**, generated in the Brain admin, shown once, stored **encrypted** at the Brain (Q2; encrypted, not hashed, because HMAC verification needs the key itself) | The scheme `BrainSurfaceSignature` already implements: `HMAC-SHA256(key, "{ts}.{nonce}.{METHOD}.{path}.{sha256(body)}")`, ±300 s, nonce replay rejected. The signed path prefix separates the two directions so one cannot be replayed as the other. Verified at the Brain by the new `brain.storage` middleware ([03 §6](03-registry-and-brain-admin.md)) |
| Members → storage app (`/internal`) | A members-to-storage **master** secret, held by members and by the Brain (encrypted). Each storage app is given only a key **derived for its own server**: `HMAC-SHA256(master, "afs-storage-control:v1:" . server_reference)` (corrected during step 06 — a single shared key would let one compromised server forge control calls to every other, contradicting §6) | The storage app trusts `/internal` calls signed with its derived key and nothing else |
| Client → device | Upload/download **token** | Asymmetric — see §3 |

A rotated per-server secret takes effect immediately; the previous secret is accepted only for the skew window so in-flight requests finish, then rejected. Because the Brain must hold recoverable secrets, **a compromised Brain database plus application key exposes every server secret** — acceptable, since the Brain is already the root of trust for the estate, and stated here so it is a decision, not a surprise. A leaked
per-server secret lets an attacker impersonate **that server only**, to the Brain, and
report figures for the devices that server hosts. It cannot read another server's config, mint
tokens, or reach members.

> **Amendment 2026-10-11 ([12](12-server-enrollment.md)).** The per-server secret is minted by the Brain at **enrollment** and returned once to the caller that proves it is the server staff meant (a one-time code pushed over SSH, or a matching source address); it is never shown to or typed by a person. The members master key is created and kept by the Brain and never sent out — members receives only the derived per-server keys. The enrollment endpoint is the one unsigned route on the storage surface; its threat table is in step 12 §10.

## 3. Token signing

Members signs upload and download tokens with a private key that **only members holds**
([06](06-direct-upload-protocol.md) §3). Storage apps receive only the public key(s), through the
Brain, with key ids. Consequences:

- A compromised storage server can verify tokens; it cannot mint one, so it cannot grant
  itself or anyone access to another device or another member.
- Every token names one device (`dev`) and one operation family (`ops`). A device presented
  with a token for a different device rejects it, so a token stolen from device 3's traffic
  cannot be replayed against device 4.
- Tokens are short-lived; download tokens are single-use (`jti` recorded).
- Key rotation is overlapped: publish the new public key, start signing with it, retire the old
  one after the longest token lifetime.

## 4. The storage app's own containment

The device holds the filesystem, so it enforces path safety itself and does not rely on members:

- The ported `NameValidator` and `PathResolver::resolve()` (reject → validate → NFC → POSIX
  split → join → `realpath()` containment) run on **every** path the app touches, at grant
  time and again at commit ([cloud-storage 12 §3](../cloud-storage/12-security.md)).
- `.part` files are named by opaque `fileId` under `.staging/{sessionId}/`; a hostile path has no
  filesystem contact until it has been validated at commit.
- The device number in the hostname, the token, the grant and the path prefix
  (`/mnt/storage{N}`) must all agree. Any disagreement is a `404`.
- The app never creates symlinks and never follows one during a walk.
- `/internal/ops` accepts only the fixed operation set in [05](05-storage-app.md) §4 with
  validated arguments. There is **no "run this path" operation**.
- The traversal corpus and the ownership tests from cloud-storage 12 are re-run against this
  app, and the shared fixture corpus ([05](05-storage-app.md) §5) is a checked artifact.

## 5. SSRF and the Brain's outbound calls

`internal_base_url` is staff-supplied and the Brain will call it. Same rules as
[www_brain 10 §10.7](../www_brain/10-brain-owned-config-and-push.md): `https` only, must resolve
to a public address (the lab and local environments permit a configured suffix), no redirects,
only level 99 may set it. The Brain never calls a URL taken from a report.

## 6. What a compromised storage server can and cannot do

| Can | Cannot |
|---|---|
| Read and alter files on its own devices | Read or alter another server's devices |
| Report false capacity/health for its own devices — worst case: taken out of placement or over-placed | Mint upload/download tokens |
| Impersonate itself to the Brain | Reach the shared database, members' user data, or Checkpoint |
| Refuse service | Forge a members-signed control call |

Over-placement from a false report is bounded: members also uses its own ledger of bytes per
device ([04](04-placement.md) §2), taking the larger of reported and known usage.

## 7. Abuse controls

- Rate limits on the client surface per token (`jti`/`sub`), and a cap on concurrent sessions
  and bytes per grant — a token cannot upload beyond the granted sizes.
- The 64 MiB per-chunk cap and the concurrent-stream cap that
  [cloud-storage 12 §8](../cloud-storage/12-security.md) noted as unenforced are enforced here,
  where the bytes actually arrive.
- Audit log on the device (rejected paths, rejected tokens, every commit/ops call with byte
  totals), separate from the app log and long-retained, in the same shape as members'
  `storage_audit` channel. Never logged: file contents, tokens, full paths at debug level.
- nginx/Apache in front sets `proxy_request_buffering off`, a body-size limit matching the chunk
  cap, and per-request timeouts (a stalled chunk is dropped and resumed) — deployment
  prerequisites listed in [11](11-lab-and-rollout.md).

## 8. TLS

Wildcard certificate for `*.storage-device.{base_domain}` on every storage server; HTTP is
redirected or refused. Members→storage and Brain→storage control calls use HTTPS with the same
certificate rules as any other Brain call.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Credential model (per-server secret, members→storage key), signed-request verification on both directions | A request signed for one direction is rejected on the other; a rotated secret rejects the old one |
| **P1** | Token verification with `dev`/`ops`/`kid`/`jti` checks and cross-language vectors | Every claim mismatch in §3 is rejected |
| **P2** | The containment port, ownership/traversal test suites, chunk and concurrency caps, device audit log | The cloud-storage corpus and ownership tests pass against the storage app |
| **P3** | The §6 table exercised: a storage server given a forged token, a forged control call, a wrong-device token | Each attempt is rejected and audited |

## As built (2026-09-27)

Repos touched, all uncommitted: **storage_device** (single-use download tokens, concurrency cap, rate limit,
consolidated ownership suite), **members** (the one end-to-end test that reused a download token, corrected).
Everything else this step asks for was already built in steps 03–07; this step's own work is P1's "download tokens
are single-use" (previously an open question) and P2's abuse controls.

- **P0** — already done (03, 06): per-server secret + `brain.storage` verification; the members→storage master with a
  key derived per server (`StorageControlKey` / `DeviceSignature::controlKey`); a request signed for one direction
  rejected on the other (`ControlSurfaceAuthTest`, `BrainSurfaceTest`); a rotated secret rejects the old one past its
  grace window.
- **P1** — token claim checks already done (06). **New: single-use download tokens**, resolving the tension flagged in
  06 §3 (single-use vs. resumable ranged download). Decision: a download token is spent on its **first** use, of any
  kind — full or ranged, successful or not reaching EOF. A resumed download asks members for a fresh token, the same
  remedy a client already has for an expired upload token, and minting one is stateless (no device call). Enforced by
  `App\Services\DownloadTokenLedger::claim()`, an atomic `INSERT OR IGNORE` on `jti` (storage_device's own table, so
  two requests racing a captured token cannot both win). Cross-language vectors (`token-vectors.json`, from 06) are
  unaffected — they check verification, not replay.
- **P2** — traversal corpus and containment: done (05). **New:** `tests/Feature/OwnershipTest.php` consolidates the
  "every id-taking handler returns 404, never 403" checks that were previously only spread across other files.
  **New abuse controls**, both configurable via `config/storage_device.php`:
  - **Concurrent-stream cap** (`max_concurrent_streams_per_session`, default 4): a self-healing cap with no permanent
    counter — a file is "in flight" only while it is `uploading` *and* its last touch is within
    `concurrency_window_seconds` (default 30s), so a crashed stream's shadow expires on its own instead of locking a
    session out forever.
  - **Per-member rate limit** (`rate_limit_per_minute`, default 600) on the whole client surface, keyed by the
    token's `sub` (not the caller's IP, which a shared NAT or mobile carrier would make useless), shared across
    upload and download routes.
  - The device audit log gets two new events: `upload.concurrency_limited`, `client.rate_limited`.
  - `storage:prune-ops` now also prunes spent-token rows whose `exp` has passed regardless (a `2026_09_27_000003`
    migration, `spent_download_tokens`).
- **P3** — the §6 table's three attempts (forged token, forged control call, wrong-device token) were already
  exercised in 05–07's tests (`TokenVerificationTest`, `ControlSurfaceAuthTest`, `BrainSurfaceTest`) and, when the
  lab/e2e harness runs, in `StorageDeviceEndToEndTest::test_a_token_for_another_device_or_session_is_refused_by_the_real_device`.
  Nothing new was needed here.
- **§5 SSRF** and **§8 TLS** are unchanged: SSRF checks on `internal_base_url` are step 03's `StorageRegistry`; TLS is
  a deployment prerequisite for step 11, not code.
