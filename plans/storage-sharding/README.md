# storage-sharding — Planning Index

**Storage Sharding** replaces the single hardcoded storage mount (`/mnt/node1`) that
[cloud-storage](../cloud-storage/README.md) writes to with a **fleet of storage devices**
spread across as many storage servers as the estate needs. Staff register servers and devices
in the Brain (`www`) admin; the members app places each member's uploads onto a device; the
client then uploads **straight to that device** over HTTPS; and a small Laravel app on each
storage server polls its devices and reports their health back to the Brain.

```
 staff ──▶ Brain admin ──▶ brain_storage_servers / brain_storage_devices   (www owns the schema)
                                   ▲  reports                  │ read (shared DB)
                                   │                           ▼
   storage app (per server) ──────┘                     members: placement, quota, tokens
        │  /mnt/storage1 … /mnt/storageN                        │ token + upload URL
        ▲                                                       ▼
        └──────────── PUT chunks / GET ranges ◀──────────── client (web · desktop · mobile)
              https://{N}.storage-device.members.appfactory.studio
```

> ### Read this before anything else
>
> - **Nothing in this plan writes code to `packages/afsserver`.** That folder holds plans and
>   server scripts. The storage app is a new Laravel repository under `w/`.
> - **The shared database belongs to `www` and `members` only.** The storage app never
>   connects to it. It talks to the Brain over the signed HTTP channel that
>   [www_brain step 10](../www_brain/10-brain-owned-config-and-push.md) already defines.
> - **`/mnt/node1` and `/mnt/node2` are not storage devices.** They are already used for
>   mailboxes, module documents, temp/work directories and a backup target. Storage devices are
>   `/mnt/storage1`, `/mnt/storage2`, … and the numbering starts at **1**.
>
> **Update 2026-10-02 — members' local mode now uses `/mnt/storage1` too.** Until placement is turned on,
> members writes to one local mount. That root (`PathResolver::STORAGE_ROOT`) moved from `/mnt/node1` to
> `/mnt/storage1`, so cloud-storage no longer shares any path with the mail/temp/backup volumes. The layout under
> it is unchanged and identical to a device's (`{root}/{member}/storage/{bucket}/…`), so local mode is simply device 1.
> Both labs now attach `STORAGE_DISK_COUNT` (2) extra disks per VM (`attach_storage_disks`) and mount them with
> `provision/mount-storage-devices.sh` (label `STORAGE{N}`, `/mnt/storage{N}`, marker `.afs-storage-{N}`). Existing
> data under `/mnt/node1/{ref}/storage` would have to be moved by hand; none exists in either lab (plan 10: no migration).

## Steps

| Step | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem, goals, non-goals, glossary, the decisions, the open questions |
| 01 | [01-current-state.md](01-current-state.md) | What exists today: the hardcoded mount, every place cloud-storage assumes a local disk, the Brain registry and channel this builds on |
| 02 | [02-naming-and-addressing.md](02-naming-and-addressing.md) | `storageN`, `/mnt/storageN`, the `{N}.storage-device.…` hostname, mount markers |
| 03 | [03-registry-and-brain-admin.md](03-registry-and-brain-admin.md) | `brain_storage_*` tables (www migrations) and the Brain admin screens where staff enter capacity |
| 04 | [04-placement.md](04-placement.md) | Which device a session lands on; capacity maths; how a bucket can outgrow a device |
| 05 | [05-storage-app.md](05-storage-app.md) | The Laravel app on each storage server: routing by hostname, endpoints, private state, scheduler |
| 06 | [06-direct-upload-protocol.md](06-direct-upload-protocol.md) | Session grants, signed tokens, direct chunk upload and download, commit, resume |
| 07 | [07-health-polling-and-reporting.md](07-health-polling-and-reporting.md) | The cron poll, what is measured, how reports change device state |
| 08 | [08-security.md](08-security.md) | Trust boundaries, token signing, the storage app's own containment, what a compromised storage server can and cannot do |
| 09 | [09-replication-later.md](09-replication-later.md) | Not built now. The hooks this plan leaves so replication is an addition, not a rewrite |
| 10 | [10-members-and-client-changes.md](10-members-and-client-changes.md) | What changes in the cloud-storage code and the three clients |
| 11 | [11-lab-and-rollout.md](11-lab-and-rollout.md) | The vagrant lab's storage VM, DNS/TLS, phases, test matrix, acceptance |
| 12 | [12-server-enrollment.md](12-server-enrollment.md) | Registering a storage server with no keys to copy: enrollment by code over SSH or by address, the storage agent, self-registering estate keys, moving a server (supersedes Q2) |

## Completion documents

Each numbered document gets a completion document in `complete/` when its work lands, named
after it — `05-storage-app.complete.md`. Include status, summary, files changed, verification
steps, and follow-up work. Phases (P0, P1, …) live inside their step's file; there is no
per-phase completion file.

## Code documentation standard

Same as cloud-storage: PHPDoc on every file, class, public method and route handler, naming
the plan step it implements; JSDoc on TypeScript. Every security check carries a comment naming
the threat it defends against.

## One-paragraph summary

Today every member's files go to one directory tree on one mount, and the members app writes
the bytes itself. This plan makes storage a **registry of devices** the Brain administers
(`storage1`…`storageN`, each with a staff-entered capacity, hosted on a storage server), a
**placement algorithm** in members that puts each upload session on one healthy device with
room, and a **storage app** on each storage server that receives the bytes directly from the
client using a short-lived signed token — so members authorises and accounts but never carries
a file. A cron job in the storage app measures each device (`df`, a write probe, a mount
marker) and reports to the Brain, which marks a device healthy, full, degraded or offline and
thereby steers placement. A device is 10 TB at most and a server hosts 7–15 of them, so the
fleet grows by mounting devices and adding rows in the Brain admin — no code change, no
deploy. Replication is deliberately later, but every table and token in this plan is shaped so
a file can have more than one copy without rework.
