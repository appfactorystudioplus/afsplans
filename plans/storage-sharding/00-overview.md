# 00 — Overview

## Completion document

When this plan step is completed, write `complete/00-overview.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code with block comments naming the plan step. Every security
check names the threat it defends against.

---

## 1. The problem

[cloud-storage](../cloud-storage/README.md) stores every member's files under one constant,
`PathResolver::STORAGE_ROOT = '/mnt/node1'`, on the same machine that runs the members app.
That has four limits, and they arrive in this order:

1. **It is one device.** A device tops out at about 10 000 GB. The unlimited bucket (bucket 4,
   capacity `-1`) means "no plan limit", not "infinite hardware" — one heavy member can fill
   the device for everybody.
2. **Devices cannot be created on demand.** A server holds 7–15 of them, and more capacity
   means standing up a new server and mounting new devices. The software has to make that a
   data change, not a deploy.
3. **The members server carries every byte.** Every upload and download streams through the
   members app, so members' bandwidth, not the devices, is the ceiling.
4. **`node1` and `node2` are taken.** They already hold mailboxes, module documents,
   temp/work directories and a backup target, in the lab and in production. Storage devices
   need a name that cannot be confused with them.

## 2. Goals

| # | Goal | Measured by |
|---|---|---|
| S0 | **Staff describe storage in the Brain admin**: servers, devices, capacity per device, state. Adding a server or device needs no code change and no deploy | [03](03-registry-and-brain-admin.md); acceptance R1–R3 |
| S1 | **Every upload session lands on exactly one healthy device with room**, chosen by an algorithm, not by a hardcoded path | [04](04-placement.md); acceptance P1–P6 |
| S2 | **Bytes go straight from the client to the device.** Members authorises, places and accounts; it never carries a file | [06](06-direct-upload-protocol.md); acceptance U1–U6 |
| S3 | **The storage app measures its devices and reports to the Brain on a schedule**, and a stale or failing report stops new placement on that device | [07](07-health-polling-and-reporting.md); acceptance H1–H5 |
| S4 | **A bucket may outgrow a device** without breaking browse, download, rename or bucket moves | [04](04-placement.md) §5, [10](10-members-and-client-changes.md) |
| S5 | **The storage app never touches the shared database** and a compromised storage server cannot mint upload tokens or read another server's files | [08](08-security.md); acceptance X1–X6 |
| S6 | **Replication can be added later without a rewrite** | [09](09-replication-later.md) |

## 3. Non-goals

- **Replication itself.** Step 09 records the hooks and nothing more.
- **Automatic rebalancing or evacuation of a device.** A `draining` state exists (no new
  placement); moving existing data off a device is a later step.
- **Provisioning servers.** Creating the VM, attaching and formatting the devices is done by
  hand or by the lab's provisioning scripts ([11](11-lab-and-rollout.md)). The Brain records
  what exists; it does not create it.
- **Changing quota economics.** Plans, buckets, cascade routing and retention
  ([cloud-storage 08](../cloud-storage/08-storage-retention.md)) are unchanged. Placement picks
  a *device*; the bucket cascade still picks a *bucket*.
- **Changing what `/mnt/node1` and `/mnt/node2` are used for.** Untouched.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Storage server** | A machine that hosts 7–15 storage devices and runs one storage app |
| **Storage device** | One mounted volume, `/mnt/storage{N}`, at most ~10 000 GB. Identified by its number `N`, unique across the whole fleet, starting at 1 |
| **Storage app** | The Laravel application on each storage server ([05](05-storage-app.md)) |
| **Registry** | `brain_storage_servers` and `brain_storage_devices`, owned by www |
| **Declared capacity** | The bytes staff typed in for a device |
| **Reported capacity / usage** | What the storage app measured with `df` |
| **Usable capacity** | The smaller of declared and reported total, minus headroom |
| **Placement** | Choosing a device for a new upload session ([04](04-placement.md)) |
| **Grant** | A signed instruction from members to a storage app: "expect this session, with these files, for this member" |
| **Upload token** | A short-lived signed credential the client presents to a device |
| **Watermark** | A fill percentage above which a device receives no new sessions |

## 5. The decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **Devices are `storage{N}`, mounted at `/mnt/storage{N}`, `N` starts at 1.** `node1`/`node2` are not touched | Removes the ambiguity the reserved names cause; a number alone identifies a device fleet-wide |
| **D2** | **The registry lives in www's migrations as `brain_storage_*`.** Members reads it through the shared database; nothing else does | AGENTS §5: www owns every migration for the shared schema, and `brain_*` means "Brain control plane" |
| **D3** | **The storage app has its own private state and never connects to the shared database** | The shared database is shared with `www` and `members` only. A storage server is a separate trust domain |
| **D4** | **The storage app and the Brain talk over the existing signed channel**: pull config, report state/events, receive push. It reuses the `afs/brain-client` scheme rather than inventing another | One HMAC scheme to reason about ([www_brain 10](../www_brain/10-brain-owned-config-and-push.md) B13–B15) |
| **D5** | **Placement runs in members, whole-session-one-device, sticky per `(member, bucket)`, falling back to the emptiest device under its watermark** | Keeps a bucket on one device in the normal case, and the unlimited bucket safe in the abnormal one |
| **D6** | **Every file row records its device.** The path is derived from the row, never from a formula on the member reference | The only way a bucket can span devices, and the hook replication needs |
| **D7** | **Bytes go direct to the device; members holds the truth.** Members creates the session and the grant, the device holds the `.part` files, and members writes the file rows only after the device confirms commit | Bandwidth scales with devices; the DB and ledger stay authoritative and transactional in one place |
| **D8** | **Upload tokens are asymmetrically signed by members; devices hold only the public key** | A compromised storage server can verify tokens but never mint one |
| **D9** | **Hostname is `{N}.storage-device.members.appfactory.studio`; the base domain is configuration** | One name per device, so replication and per-device failure handling need no new addressing; the lab uses a different domain |
| **D10** | **A device with no fresh report receives no new sessions** | "Unknown" must never read as "healthy" |

## 6. Questions — all resolved

| # | Question | Depends on | Resolution |
|---|---|---|---|
| Q1 | Repository directory name for the storage app under `w/` | [05](05-storage-app.md) | **`storage_device`** (user, 2026-09-26) — avoids colliding with Laravel's own `storage/` directory in every path and command |
| Q2 | Per-server credential: one shared app key, or a secret generated per server in the Brain admin? | [03](03-registry-and-brain-admin.md), [08](08-security.md) | **Per-server**, generated in the dashboard and shown once (user, 2026-09-26), so adding a server needs no `.env` change on the Brain. **Superseded by Q6 / [step 12](12-server-enrollment.md):** still per-server and still encrypted at the Brain, but minted at enrollment and never shown or pasted |
| Q3 | Default watermarks | [04](04-placement.md) | **Soft 85 %** (no new sessions), **hard 95 %** (reads and commits only), both configurable per device (user, 2026-09-26) |
| Q4 | Downloads: redirect the client to the device (`302` with a token) or have the client ask members for a download URL first? | [06](06-direct-upload-protocol.md) | **Members returns a JSON URL + token** (decided in the plan; the user has not objected). A `302` breaks `Authorization` on cross-origin fetches in some clients |
| Q5 | Should the lab get a dedicated `storage` VM, or should the existing `web` VM host the first devices? | [11](11-lab-and-rollout.md) | **A dedicated `storage` VM** (user, 2026-09-26) — the only way to prove the cross-server path |
| Q6 | Must staff copy a server's credential and the estate keys by hand? | [12](12-server-enrollment.md) | **No** (user, 2026-10-11) — supersedes Q2: the Brain and the storage app exchange what they need at enrollment and each keeps it in its own database; `.env` carries only `BRAIN_URL` |

## 7. Decision on scope of first delivery

One storage server and one to three devices, direct upload, health reporting, admin screens.
Everything else in this plan is designed now and built when the fleet needs it.
