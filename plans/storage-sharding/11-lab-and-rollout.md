# 11 — Lab and rollout

## Completion document

When this plan step is completed, write `complete/11-lab-and-rollout.complete.md`.

---

## 1. The lab today

`C:\projects\vagrant` runs three VMs (`AFS-DEPLOYMENT-VAGRANT.md` §1): `dns` (BIND, zone `test1`),
`web` (the Brain and all ten apps) and `web2` (the tenant host). Every VM has two 10 GB disks
labelled `NODE1`/`NODE2`, attached in the `Vagrantfile` by `attach_node_disks` and mounted by
`provision/mount-node-storage.sh`. Apps are read live from `/vagrant/afs-eco/w/<name>` and
deployed with `vagrant provision web --provision-with app-<name>`.

## 2. What the lab needs

| Need | Change (all outside `w/`, in the lab repo) |
|---|---|
| A storage server that is **not** `web` | A fourth VM, `storage`, so the cross-server path (browser → device host, Brain ↔ storage over the network) is genuinely exercised. Decided ([00 §6](00-overview.md) Q5); the alternative — hosting the first devices on `web` — proves less |
| Devices `storage1…storageN` | `attach_storage_disks` alongside `attach_node_disks`: N small virtual disks on the new VM's SATA controller, and a `provision/mount-storage-devices.sh` modelled on `mount-node-storage.sh` — label `STORAGE{N}`, mount `/mnt/storage{N}`, `fstab` by label with `nofail`, ownership for the web server user, and it **also** creates the marker `.afs-storage-{N}` |
| Hostnames | Records for `{N}.storage-device.members.appfactory.test1` in the `dns` zone, pointing at the `storage` VM |
| TLS | A wildcard certificate for `*.storage-device.members.appfactory.test1` from the lab's CA (`web-tls.sh` is the precedent) |
| The app | A deploy script for the storage app modelled on `provision/deploy-app.sh`, plus a cron entry for `schedule:run` |
| The Brain able to call it | The Brain reaches the storage app's `internal_base_url`; the lab's private network already routes `192.168.56.0/24` |

Lab hostnames use `.appfactory.test1` because the base domain is configuration
([02](02-naming-and-addressing.md) §1).

## 3. Deployment prerequisites for production

Not code; listed so an operator has them in one place:

- Wildcard DNS or per-device records and the wildcard certificate for
  `*.storage-device.members.appfactory.studio`.
- Web server in front of the storage app: `proxy_request_buffering off`, a request body limit
  matching the 64 MiB chunk cap, generous but finite read/send timeouts, `sendfile` on for
  downloads (and see the lab's "sendfile gotcha" note before enabling it on a shared folder).
- `* * * * * php artisan schedule:run` for the storage app user.
- Each device formatted, labelled `STORAGE{N}`, mounted by label, marker created with
  `storage:init-device {N}` **after** confirming the mount, then added in the Brain admin.
- Firewall: the storage app is reachable from clients (HTTPS) and from the Brain and members
  (HTTPS, control surface); nothing else.

> **Amendment 2026-10-11 ([12](12-server-enrollment.md)).** Bringing a server up no longer includes carrying keys between machines: upload the code, press **Connect** in the Brain (or let the server claim itself), and the agent configures and enrolls it.

## 4. Repository housekeeping

- The storage app is a new repository under `w/`. `AGENTS.md` §2 ("the ten repositories") and the
  table in it are updated in the same change, as is §5's list of table prefixes
  (`brain_storage_*`) and the plans list in §3.
- Any change to the lab repo (`Vagrantfile`, `provision/`, DNS zone) is a change in **that**
  repository and is reported separately, per AGENTS §2's rule that a change spanning
  repositories is several changes.
- No code goes to `packages/afsserver` — only this plan folder.

## 5. Rollout phases (order across steps)

| Phase | Steps | Result |
|---|---|---|
| **R0** | 02 P0, 03 P0–P1 | Registry tables exist; a test client can report to the Brain |
| **R1** | 05 P0–P1, 08 P0, 06 P0 | Storage app skeleton on the lab `storage` VM, signed channels both ways, tokens verify |
| **R2** | 03 P2, 07 P0–P1 | Staff add the server and devices in the admin; reports flow; health derives |
| **R3** | 04 P0–P2, 06 P1–P2, 10 P0–P2 | An upload placed by members lands on a lab device, direct from a client; download works |
| **R4** | 10 P3 | Web, then desktop, then mobile against the lab |
| **R5** | 07 P2, 08 P1–P3, 04 P3, 10 P4 | Staleness, security matrix, multi-device buckets |
| **R6** | Production: DNS/cert, first server, first devices | Real fleet with one server and 1–3 devices |

## 6. Test matrix

| Area | Cases |
|---|---|
| Registry (03) | Add server/device; device number ≥ 1 and never reused; capacity cannot go below used; device with files cannot be deleted; credential shown once and rotated |
| Placement (04) | Sticky preference; spill on soft watermark; deterministic tie-break; stale report ineligible; `draining`/`readonly`/`offline` ineligible; no candidate ⇒ `503` not `413`; concurrent sessions cannot both take the last bytes |
| Protocol (06) | Multi-chunk direct upload; resume from device offsets; token expiry mid-upload; wrong-device token; grant missing; commit idempotent; download with `Range` |
| Health (07) | Unmount ⇒ `offline`; marker removed ⇒ `offline`; write probe failure; two-clean-reports recovery; storage app stopped ⇒ `unknown`; Brain unreachable ⇒ storage app keeps serving |
| Security (08) | Forged token; forged control call; direction replay; rotated secret; every traversal-corpus entry against the storage app; ownership 404-not-403 through the token+grant path |
| Multi-device (04 §5, 10 P4) | Bucket spanning two devices: rename, delete, move-bucket; fan-out killed after device 1 and resumed |
| Clients (10) | Web, desktop, mobile: upload, token refresh, resume after kill, download, `503 STORAGE_UNAVAILABLE` message |

## 7. Acceptance

| # | Criterion |
|---|---|
| R1 | Staff add a storage server and three devices in the Brain admin with no code change or deploy |
| R2 | The Fleet screen shows declared vs reported capacity and a health value per device |
| R3 | Deleting a device that holds files is refused; retiring it is allowed |
| P1 | An upload is placed on a device chosen by the algorithm, and `device` is recorded on the session and file rows |
| P2 | With one device over its soft watermark, new sessions go elsewhere; with none eligible the answer is `503 STORAGE_UNAVAILABLE` |
| P3 | A bucket that has spilled across two devices lists, downloads, renames and moves correctly |
| U1 | The members app carries none of a file's bytes: with members' network cut mid-chunk, an in-flight range PUT to the device still completes |
| U2 | A token for device 2 is rejected by device 1 |
| U3 | An upload longer than the token lifetime completes via refresh |
| U4 | Interrupting an upload and resuming re-sends at most one chunk, from the device's offsets |
| H1 | Unmounting a device turns it `offline` within two poll intervals and stops placement on it |
| H2 | A device does not return to `healthy` until two consecutive clean reports |
| H3 | Stopping the storage app turns its devices `unknown` within `report_ttl` |
| H4 | With the Brain unreachable, the storage app keeps accepting uploads for already-granted sessions |
| X1 | The storage app has no shared-database credential and `grep` for one finds none |
| X2 | A forged token, a forged control call, and a replayed opposite-direction signature are all rejected and audited |
| X3 | The cloud-storage traversal corpus and ownership tests pass against the storage app |

## 8. Risks

- **Multi-device operations are not atomic.** Mitigated by the journal and idempotent ops, and
  by rule 2 of [04](04-placement.md) keeping the normal case single-device. This is the most
  intricate piece of the plan; P4 is last for that reason.
- **A mount that fails silently fills the boot disk.** Mitigated by the marker check before
  every write, at the cost of one `stat` per request.
- **Members and a device disagreeing after a crash between `rename(2)` and the row write.**
  Mitigated by idempotent commit now and `ReconcileStorage` later ([06](06-direct-upload-protocol.md) §5).
- **Wildcard certificate and DNS are outside the codebase.** Called out in §3 so they are not
  discovered at cut-over.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | The lab `storage` VM with two or three devices, DNS records and certificate | `df` on the VM shows `/mnt/storage1..N`; the hostnames resolve and serve TLS |
| **P1** | Storage app deploy script and cron | `schedule:run` fires; the app answers on a device hostname |
| **P2** | Rollout phases R0–R5 executed and the test matrix run | Every acceptance row above is met or explicitly recorded as not met in the completion document |
| **P3** | `AGENTS.md` updated; production prerequisites handed to the operator | The eleven-repository table and `brain_storage_*` prefix are documented |

## As built (2026-10-02, partial P0)

Added to **both** labs (`lab1`, `lab2`): `attach_storage_disks` in the `Vagrantfile` (`STORAGE_DISK_COUNT = 2` disks per VM, `<vm>-storage<N>.vdi`,
SATA ports 2.., controller grown to 4 ports) and `provision/mount-storage-devices.sh` (label `STORAGE{N}`, `/mnt/storage{N}`, `nofail`, uid 1500,
marker `.afs-storage-{N}` written only after `mountpoint -q` succeeds), provisioner name `mount-storage-devices`. Per VM, so device numbers
repeat across VMs; fleet-unique numbers are assigned in the Brain admin. **Still not built:** the dedicated `storage` VM, the
`{N}.storage-device.…` DNS records, the wildcard certificate and the storage-app deploy script. Nothing was booted; `vagrant validate` passes for lab2.
