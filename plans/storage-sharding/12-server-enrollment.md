# 12 — Storage server enrollment (no keys to copy)

## Completion document

When this plan step is completed, write `complete/12-server-enrollment.complete.md`.

---

Added 2026-10-11 at the user's request. It **supersedes decision Q2** ([00](00-overview.md)): a server's secrets are no
longer generated, shown once and pasted into the storage app's `.env` by a person. Staff tell the Brain *which server*
(an address, a domain, and optionally a login); the Brain and the storage app then trade what they need to trust each
other, and each side keeps what it learned in its own database.

The goal in one sentence: **registering a storage server, moving it, and rotating its secrets involve no file edits and
no copy-pasted keys.**

## 1. What is manual today

| # | Manual step today | Where it lives |
|---|---|---|
| M1 | Copy the server **reference** and **credential** (shown once) from the Brain into the storage app's `.env` | [03](03-registry-and-brain-admin.md) §4, [08](08-security.md) §2 |
| M2 | Generate the members→storage **master key** on members (`storage:control-key-generate`), set it in members' `.env` **and** `PUT` the same value into the Brain | [08](08-security.md) §2 |
| M3 | Generate members' **token key pair** (`storage:token-key-generate`), set `STORAGE_TOKEN_KID` / `…_PRIVATE_KEY_PATH`, and register the public key in the Brain | [06](06-direct-upload-protocol.md) §3 |
| M4 | Deploy the app, write the `.env`, `key:generate`, `migrate`, add the cron line | [05](05-storage-app.md) §8, README |
| M5 | `storage:init-device N` on the server for each device, then add the device in the Brain | [02](02-naming-and-addressing.md) §2 |
| M6 | Add the DNS record for each `{N}.storage-device.…` name | [02](02-naming-and-addressing.md) §3 |
| M7 | Moving a server to new hardware: redo M1, M4, M5 by hand and re-point DNS | — |

## 2. Goals and non-goals

| # | Goal | Done when |
|---|---|---|
| E-G1 | Add a server by **address (IP and/or domain)**; the secrets are exchanged by the two apps, never typed or shown | M1 gone |
| E-G2 | The storage app keeps its identity in **its own database**; `.env` carries only `BRAIN_URL` | M1, M4 `.env` edits gone |
| E-G3 | The estate keys (M2, M3) create and register themselves | M2, M3 gone |
| E-G4 | Moving a server is "Re-pair" in the Brain plus pointing the devices at it; everything is tracked in an event history | M7 gone |
| E-G5 | Where the Brain can reach the server over SSH it can also **do the work**: configure the app, enroll, discover devices | M4, M5 mostly gone |

Non-goals: formatting or partitioning a disk (destructive; stays a deliberate human act, [11](11-lab-and-rollout.md));
uploading the application code (the operator uploads it; nothing here fetches or edits code on a server); changing the
signature scheme between the apps (still HMAC, [08](08-security.md) §2 — only *who mints the secret* and *where it is
kept* change).

## 3. The idea

A server record in the Brain gains an **enrollment state**: `awaiting` → `enrolled` → (re-opened to `awaiting` by
"Re-pair"). While `awaiting`, the Brain will hand out the server's credential **once**, to a caller that proves it is the
server staff meant. There are two ways to prove it, and both end at the same endpoint:

| Channel | How the proof works | Needs |
|---|---|---|
| **A. Pushed over SSH** (preferred) | The Brain logs in to the server with the SSH login on the record (the machinery it already uses for web and DNS servers: `SshRunner` → `core/scripts/ssh/afs-ssh-run`), runs the **storage agent** there and passes a **one-time enrollment code** on stdin. The agent hands it to the storage app, which presents it to the Brain. Nobody sees the code | An SSH login (user + password, stored encrypted on the record exactly like `brain_http_servers`) |
| **B. Claimed by address** (fallback) | The storage app, finding it has no identity, asks the Brain to enroll. The Brain matches the caller's **source address** to exactly one `awaiting` server (its IPs, or the A/AAAA records of its domain). No match, or more than one → the attempt is listed under **Unrecognised servers**, and staff pick which record it is with one click | Nothing on the server except `BRAIN_URL` |

Either way the Brain checks the thing the caller is *claiming* (a code, or an address), mints the credential, returns it
in the response, and the storage app stores it **encrypted in its own SQLite database**. The Brain stores its copy
encrypted as today. After the first signed request with that credential the Brain marks the server `enrolled` and the
window is closed.

## 4. The enrollment protocol

`POST {BRAIN_URL}/api/internal/brain/storage/enroll` — **unsigned** (the caller has no secret yet), TLS only, rate-limited
per source address, and the only unsigned route on the storage surface.

```
request   { "claim": "<sha256 of a random token the app keeps>", "code": "<one-time code>"|null,
            "app_version": "0.1.0", "host": "<gethostname()>", "addresses": ["<local ips>"] }
response  201 { "reference": "stor-ab12cd34ef", "credential": "<secret>", "poll_after": 60 }
          202 { "status": "pending_approval" }          (channel B, no unique match: wait for staff)
          403/404 bare                                   (window closed, wrong code, unknown caller)
```

1. **The claim token.** The app generates 32 random bytes the first time it tries, keeps them locally and sends their hash.
   The Brain stores the hash with the attempt. If the response is lost, the app retries with the same claim and gets the
   **same** credential back (only while the server is not yet confirmed) — and nobody else can, because they do not have
   the token behind the hash.
2. **Code (channel A).** 24 random bytes, stored at the Brain as a hash, valid 15 minutes, single use. Created when staff
   press **Connect** and sent straight into the SSH call.
3. **Address match (channel B).** The Brain reads the caller's address from the proxy header nginx sets
   (`X-Real-IP`, [afs-proxy.conf](../../tmp/__afs_proxy.conf)); `www` must trust that proxy. A domain is resolved at match time.
4. **Confirm.** The first request signed with the new credential (the config pull) sets `confirmed_at`. From then the
   enrollment endpoint answers 403 for that server until staff press **Re-pair**.
5. **Everything is an event.** `enroll_requested`, `enroll_matched`, `enroll_pending_approval`, `enroll_approved`,
   `enroll_issued`, `enroll_confirmed`, `enroll_refused` — each with the source address, app version and host name — in a new
   append-only `brain_storage_server_events` table shown on the server's page. That page is also "where did my server go":
   the address it enrolled from, when it was last seen, and from where.

## 5. Where things are kept

**Brain (`www`, which owns the schema)** — a new migration on `brain_storage_servers`: `enrollment_state`
(`awaiting|enrolled`), `enroll_opened_at`, `enroll_expires_at` (default 24 h), `enrolled_at`, `confirmed_at`,
`enrolled_from_ip`, `last_seen_ip`, `app_version`, `ssh_user`, `ssh_port`, `ssh_password_encrypted`, `claim_hash`; plus
`brain_storage_enrollment_attempts` (unmatched callers waiting for staff) and `brain_storage_server_events`. Servers that
exist when this ships are migrated to `enrolled` and keep working untouched.

**Storage app** — a `server_identity` table (one row) in its private SQLite database: `reference`, `credential_enc`,
`credential_next_enc`, `brain_url`, `enrolled_at`. Secrets are encrypted with the app's own `APP_KEY`. An `Identity`
service replaces the `config('storage_device.brain.*')` reads in `BrainClient` and `VerifyBrainSignature`; the old `.env`
values (`STORAGE_SERVER_REFERENCE`, `STORAGE_SERVER_CREDENTIAL`) still win when set, so an existing server needs no change.
`storage:enroll` runs from the scheduler every minute while there is no identity, and by hand.

## 6. The storage agent — letting the Brain do the work

The estate already has the means to act on a server from the Brain; this step reuses it rather than inventing another:

| Piece | Used for |
|---|---|
| `app/Services/Brain/Ssh/SshRunner` + `core/scripts/ssh/afs-ssh-run` (+ `.expect`) | Run one command on the storage server over ssh with a password, the request JSON on stdin |
| `core/scripts/commands.sh` (new `--storage-*` actions), started through `FunctionsController::RunCommandBG` → `core/scripts/bin/afs-website` | Anything that must run **on the Brain's own host** as the system user (DNS file edits, reloads) and may take a while |
| A new `core/scripts/storage/afs-storage-agent` (in the Brain's checkout) | The script that runs **on the storage server**. **It is never installed there:** the Brain sends it with every call (base64 in the ssh command), the server writes it to a temp file, runs it and deletes it. Actions: `probe`, `configure`, `enroll`, `devices`, `init-device` |

Agent actions, all idempotent and all printing one JSON object on stdout:

- `probe` — PHP version and extensions, free disk, the app path, `lsblk`/`findmnt` for `/mnt/storage*`, whether an identity exists.
- `configure` — with the code already uploaded: `composer install --no-dev`, create `.env` **only if missing** (`BRAIN_URL`, a generated `APP_KEY`), `migrate --force`, install the one cron line. Never overwrites an existing `.env`.
- `enroll` — `php artisan storage:enroll --code=<stdin>`; reports the reference it received (never the credential).
- `devices` — list real mount points named `/mnt/storage{N}` with label, size, used, marker present or not.
- `init-device N` — the existing `storage:init-device`, which refuses unless `/mnt/storageN` is a real mount point.

**Never done by the agent:** editing application code or any file under the app's `app/`, `routes/`, `config/`,
`database/`; formatting, partitioning or unmounting a disk; printing a secret.

## 7. The estate keys create themselves (M2, M3)

- **Control master (M2).** The Brain creates the master secret itself the first time it needs one (stored encrypted, as
  `BrainStorageMembersKey` already is) and **never sends it anywhere**. Members is given the **derived per-server keys** it
  needs over its existing Brain app channel (`GET …/storage/control-keys`, ETag), caches them, and signs control calls with
  them. `STORAGE_CONTROL_MASTER_KEY` and `storage:control-key-generate` become optional overrides.
- **Token key pair (M3).** Members creates its own RSA pair on first use (private key in its `storage/app/keys/`, mode 0600),
  and registers the **public** key and its `kid` with the Brain over the same app channel. Rotation becomes a scheduled
  job: new `kid`, publish, sign with it after the publish has propagated, retire the old one after the longest token
  lifetime plus the Brain's grace ([08](08-security.md) §3). `STORAGE_TOKEN_*` become optional overrides.

## 8. Devices (M5, M6)

- **Discovery.** The storage app reports the real `/mnt/storage{N}` mount points it sees (number, label, size, marker
  present) with its pull and its report. The Brain lists them as **Discovered, not registered**; staff press **Adopt**,
  confirm the capacity (pre-filled from the measurement) and the device is created `pending`.
- **Marker.** When the pulled config says this server hosts device N, the mount is a real mount point and its label is
  `STORAGE{N}`, the app creates `.afs-storage-{N}` itself — the same checks `storage:init-device` makes, run by the app.
- **DNS.** Creating a device adds `{N}.storage-device.{base_domain}` through the existing DNS transport
  (`Domains\Dns`, `core/scripts/dns/afs-dns-agent`, `commands.sh --reload-dns-zone`), pointing at the server's address.
  The wildcard certificate is issued once for the whole `*.storage-device.{base_domain}` name and needs nothing per device.

## 9. Moving a server (M7)

1. Prepare the new machine (disks mounted, code uploaded). Nothing else.
2. In the Brain press **Re-pair** on the server: it enters the new address (and SSH login), state → `awaiting`.
3. **Connect** (channel A) or let the new server claim itself (channel B). The new credential becomes current; the old one
   is kept as `previous` for the existing grace window and then refused, so the old machine stops being a server.
4. Move the device records to the new server (a data change on `brain_storage_devices.server_id`); the Brain re-points the
   DNS names. If the physical volumes were moved, nothing is copied; if not, copying is a separate, deliberate job.

A copy of the old machine's SQLite file also carries its identity, so a straight clone needs no re-pair at all.

## 10. Security

| Threat | Why it does not work |
|---|---|
| Someone calls `enroll` before the real server does | Channel A needs the one-time code; channel B needs the source address to match a record staff created, inside a window (24 h) that closes at first confirmation. A mismatch is *listed for staff*, never auto-approved |
| A second machine behind the same NAT address | It matches the same record only if the first has not confirmed. If both are plausible the Brain refuses to guess (`202 pending_approval`) |
| Lost or replayed enrollment response | The claim token makes the retry return the same credential only to the holder of the token, and only until confirmation |
| The wrong machine enrolled by mistake | The server page shows the address, host name and version it enrolled from; **Re-pair** invalidates its credential. What it could do meanwhile is limited to *that server's* config pull and reports ([08](08-security.md) §2) — it cannot mint tokens or reach members |
| The credential read from the storage server | Stored encrypted with `APP_KEY` in the private database, not in a world-readable `.env`; file mode 0600. A server whose database is stolen can impersonate only itself, as before |
| Credential interception | Only over TLS to `BRAIN_URL`, once, to the caller that passed the check; never logged, never in an event |
| SSH password exposure | Same handling as today: environment of the runner only, never an argument, file or log line; stored encrypted on the record |
| Enumerating servers | The unsigned endpoint answers every failure with the same bare 403, rate-limited per address |

## 11. What stays manual

`BRAIN_URL` (a public address, not a secret — and the agent writes it); mounting disks and formatting them; the wildcard
DNS record and certificate (once per base domain, [11](11-lab-and-rollout.md) §3); uploading the application code; pressing
**Connect**, **Adopt** and **Re-pair**.

## 12. Effects on other steps

| Step | Change |
|---|---|
| [03](03-registry-and-brain-admin.md) | **Add server** takes address, domain, optional SSH login and shows no credential; adds **Connect**, **Re-pair**, **Unrecognised servers**, **Discovered devices** and the server event history. **Rotate credential** becomes automatic (§13 P1) |
| [05](05-storage-app.md) | `.env` shrinks to `BRAIN_URL` + `APP_KEY` (+ optional overrides); `Identity` service, `storage:enroll`, `server_identity` table |
| [06](06-direct-upload-protocol.md) | Members' token key is created and registered by members |
| [08](08-security.md) | §2 credentials: minted by the Brain at enrollment, not shown; the one unsigned route |
| [11](11-lab-and-rollout.md) | The deploy script ends at "agent `configure` + `enroll`"; no key hand-off between machines |

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | `enroll` endpoint, attempts table, `Identity` service, `storage:enroll`, **Add server** without a credential, channel B and **Unrecognised servers** | A fresh storage app with only `BRAIN_URL` enrolls against a record created from an address, pulls config and reports; a second caller is refused |
| **P1** | Channel A: SSH login on the record, `afs-storage-agent` (`probe`, `configure`, `enroll`), **Connect**; **Re-pair**; automatic credential rotation; server event history | Pressing Connect on a server with the code uploaded leaves it enrolled with no file edited; Re-pair moves it to a second machine and the first is refused after the grace window |
| **P2** | Estate keys: Brain-owned master and derived keys for members; members' own token key pair and scheduled rotation | `STORAGE_CONTROL_MASTER_KEY` and `STORAGE_TOKEN_*` are unset and a grant, upload and commit still work end to end |
| **P3** | Device discovery and **Adopt**, app-created marker, device DNS record through the DNS transport | Mounting a disk labelled `STORAGE3` makes it appear as Discovered; Adopt makes `3.storage-device.…` resolve and serve |

## Acceptance

| # | Check |
|---|---|
| E1 | A server added by address enrolls with only `BRAIN_URL` set; no credential appears in any response, log, event or `.env` |
| E2 | A caller whose address matches no record, or two records, is listed for staff and receives nothing until they approve |
| E3 | The enrollment response can be retried with the same claim until confirmation, and a different claim gets nothing |
| E4 | After confirmation `enroll` answers a bare 403 until Re-pair |
| E5 | Re-pair: the new machine works, the old one is refused after the grace window |
| E6 | Connect (channel A) performs `configure` and `enroll` without editing any application code, and a second Connect changes nothing |
| E7 | The agent refuses to format or touch a disk, and never overwrites an existing `.env` |
| E8 | Control calls and tokens work with no `STORAGE_CONTROL_MASTER_KEY` / `STORAGE_TOKEN_*` set |
| E9 | A server enrolled the old way (`.env` credential) keeps working with no change |
| E10 | The unsigned endpoint is rate-limited and returns the same bare 403 for unknown, expired and wrong-code callers |

## Open questions

| # | Question | Leaning |
|---|---|---|
| EQ1 | Should channel B auto-approve a unique address match, or always wait for a click? | Auto on a unique match inside the window; a per-server "require approval" switch for the cautious |
| EQ2 | Should the SSH password be kept after enrollment or discarded? | Keep (encrypted) — Re-pair, `probe` and `devices` need it; staff can clear it |
| EQ3 | Does the Brain trust `X-Real-IP` in every environment (lab nginx, production nginx)? | Needs a check on each; channel A does not depend on it |

## As built (2026-10-11)

All four phases are written and tested at unit/feature level. **Nothing has been run against a real storage server or the lab:** lab2 was unreachable
from the development PC, so the ssh agent (`afs-storage-agent`) has been syntax-checked and exercised only through the Brain's fake-process tests, and the
unsigned enroll → pull → report loop is proven in-process, not over real HTTP between two machines. Tests ran on SQLite in memory (the configured MySQL
is not reachable from here); no production code was changed to suit that.

| Phase | Built | Where |
|---|---|---|
| **P0** | `POST /api/internal/brain/storage/enroll` (unsigned, 20/min per address), `StorageEnrollment` (address match, claim token, window, bare 403s, events), attempts table and approve/refuse, **Add server** with no credential, the storage app's `Identity`, `server_identity` table, `storage:enroll` (scheduled every minute while unenrolled), `storage:status` | www: `StorageEnrollment`, `StorageAddressResolver`, `BrainStorageSurfaceController::enroll`, migration `2026_10_11_000001_add_enrollment_to_brain_storage_servers`. storage_device: `Identity`, `EnrollServer`, `ServerStatus`, migration `…000002_create_server_identity_table` |
| **P1** | Channel A: ssh login on the record, `StorageConnector` (probe → configure → enroll), `core/scripts/storage/afs-storage-agent`, `storage_agent.*` Brain settings, **Connect**, **Re-pair**, automatic credential replacement (pending credential promoted on first use, lost responses recovered), server event history | www: `StorageConnector`, `ServerLogin::forStorage`, `DomainSettings` keys, `rotate-credential` route. storage_device: `BrainClient::rotateIfDue()` |
| **P2** | The Brain creates the control master itself (`ensureMembersKey`); members fetches **derived** per-server keys (`ControlKeys`, cached encrypted, outage-tolerant, rate-limited); members creates, registers and rotates its own token key pair (`TokenKeys`, `storage:keys-sync` every 5 minutes) | www: `BrainStorageMembersSurfaceController`. members: `ControlKeys`, `TokenKeys`, `StorageKeysSync` |
| **P3** | The storage app reports its real mounts (`storage:discover`), the Brain lists them as **Discovered disks**, **Adopt** registers a pending device sized from the measurement, and the app creates the marker itself when the device is registered, the mount is real and the label is `STORAGE<N>` | www: `StorageDiscovery`, `adoptDiscovered`. storage_device: `DeviceDiscovery`, `MarkerProvisioner`, `MountProbe::label()` |

### Where it differs from the plan above

- **Rotating the credential of an enrolled server** is a request ("replace yours"), not a new secret shown to a person. A server set up the old way
  (no claim on its record) still gets a secret from **Replace credential**, once, because it has no way to ask.
- **Per-device DNS** is not pushed through the Brain's DNS engine: that engine manages member-domain zones, not the platform zone the storage names live
  in. **Adopt** instead returns the exact record (`N.storage-device.<base> → <server address>`); a wildcard record already covers devices on one server.
- **`internal_base_url` is not derived** and the Brain's push to the storage app (`brain-app/refresh`) is still not called by anything; the app pulls.
- **First token after a fresh install:** members registers a brand-new token key before signing with it, but the storage servers only learn it on their
  next pull (≤ 5 minutes). Tokens minted in that window are rejected; a rotation does not have this gap (it waits `switch_delay_seconds`).
- **The agent is pushed, not installed** (changed after the first build, at the owner's request): there is no `storage_agent.path` setting and nothing to copy to a server.
- **Connect needs two web workers on the Brain:** the Brain's ssh call is waiting while the storage app calls the Brain back to enroll (`php artisan serve`
  alone would deadlock; the real Apache/nginx setup does not).
- `afs-storage-agent` needs `jq` on the storage server (it is on every server), and the Brain host needs `expect` (already required by the existing ssh runner).

### Acceptance

| # | Result | Evidence |
|---|---|---|
| E1 | Met | `BrainStorageEnrollmentTest` (address enroll, credential in no response/log/event/`.env`), `EnrollmentTest` (stored encrypted) |
| E2 | Met | unmatched and two-match callers listed, nothing given; approve/refuse |
| E3 | Met | same claim returns the same credential until confirmed; a different claim gets nothing |
| E4 | Met | after the first signed request `enroll` answers a bare 403 |
| E5 | Met | `test_re_pairing_moves_the_server_and_the_old_machine_stops_working` |
| E6 | Met for the Brain's side only | `BrainStorageConnectTest` with a faked ssh process; the agent itself was **not run** |
| E7 | By construction, **not run** | the agent has no code path that writes application code, overwrites an `.env`, or touches a disk; unverified without `jq` here |
| E8 | Met | `ControlKeysTest`, `TokenKeysTest`, `BrainStorageMembersSurfaceTest`; a grant, upload and commit pass with no master and no token key configured |
| E9 | Met | an `.env`-identity server is untouched (`test_an_env_identity_wins_and_is_not_managed`, `test_a_server_set_up_the_old_way_is_untouched_by_enrollment`) |
| E10 | Met | `test_every_refusal_is_the_same_bare_403`, `test_the_unsigned_route_is_rate_limited_per_address` |
