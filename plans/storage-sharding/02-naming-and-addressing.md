# 02 — Naming and addressing

## Completion document

When this plan step is completed, write `complete/02-naming-and-addressing.complete.md`.

---

## 1. Names

| Thing | Form | Example |
|---|---|---|
| Device number `N` | Positive integer, unique across the whole fleet, assigned in the Brain admin, **never reused** after a device is retired | `1`, `2`, `17` |
| Device name | `storage{N}` | `storage1` |
| Mount point | `/mnt/storage{N}` | `/mnt/storage1` |
| Filesystem label | `STORAGE{N}` | `STORAGE1` — mounted by label, exactly as `mount-node-storage.sh` does for `NODE1`, so a `/dev/sdX` reshuffle cannot swap devices |
| Device hostname | `{N}.storage-device.{base_domain}` | `1.storage-device.members.appfactory.studio` |
| Storage server reference | The registry's `reference_num`, same convention as `brain_http_servers` | — |

`base_domain` is **configuration**, not a constant. Production: `members.appfactory.studio`.
The lab uses its own suffix (`.appfactory.test1` — see [11](11-lab-and-rollout.md)), so the lab
hostname is `1.storage-device.members.appfactory.test1`. Code builds a hostname from
`(N, base_domain)` in one place.

`node1` and `node2` are **not** device names and are never valid in any storage field.
Validation in the Brain admin rejects `N < 1`.

## 2. Why the number is in the hostname

- A device is addressable on its own. A client uploading to device 7 talks to `7.storage-device…`
  and to nothing else; the token names device 7; the device checks that.
- Which *server* hosts device 7 is invisible to clients and can change (DNS moves; the name
  stays). A file row records `device = 7`, not a server, so moving a device's volume to another
  server is a DNS and registry change, not a data migration.
- Replication ([09](09-replication-later.md)) needs no new addressing: a second copy is
  another device number.

## 3. DNS and TLS

- One **wildcard** record, `*.storage-device.{base_domain}`, pointing each name at the server
  that hosts that device. With 7–15 devices per server the practical form is one explicit
  record per device (so a device can be repointed independently) plus, optionally, the
  wildcard as a fallback that answers `404` from a default vhost.
- One **wildcard certificate** for `*.storage-device.{base_domain}`. A wildcard cert covers one
  label level, which is exactly `{N}.storage-device.…`.
- The lab's DNS (`dns` VM, BIND, zone `test1`) gains the equivalent records; the Brain's
  `BrainDnsController` is the existing path for that ([11](11-lab-and-rollout.md)).

## 4. How a request reaches the right device

The storage server runs one storage app ([05](05-storage-app.md)). The web server in front of it
does not need one virtual host per device: it passes the `Host` header through and the app
resolves `{N}` from it, then checks that device `N` is one this server actually hosts (from its
pulled configuration). An unknown or unhosted `N` is a `404` with no detail — never a fallback
to some other device.

## 5. Mount markers

A device that fails to mount leaves `/mnt/storage{N}` as an **empty directory on the root
filesystem**, and the next write silently fills the boot disk. Every device therefore carries a
marker file at its root, `/mnt/storage{N}/.afs-storage-{N}`, created once when the device is
first registered. The storage app checks, before **every** write and on every poll:

1. the path is a mount point (its device id differs from its parent's), **and**
2. the marker exists and names this `N`.

Either failing marks the device `offline` locally and rejects the write. The marker is created
by an explicit operator command ([05](05-storage-app.md) `storage:init-device`), never
implicitly by a write, precisely so a missing mount cannot create its own marker.

## 6. Ownership and permissions

The web server user owns `/mnt/storage{N}` and its contents, mode `0775`, as
`mount-node-storage.sh` already does for `apache`. Each member gets `/mnt/storage{N}/
{member_reference_num}/…`, created by the storage app on first write to that device — the
directory layout under a member is exactly cloud-storage's ([cloud-storage 03 §2](../cloud-storage/03-architecture.md)).

## Phases

| Phase | Deliverable | Status |
|---|---|---|
| **P0** | The naming rules as a validated helper in the Brain (number ≥ 1) and in the storage app (hostname → `N`) | **Brain side done 2026-09-26** — `www/app/Services/Brain/Storage/StorageDeviceNaming.php`, `config/brain.php` `storage.base_domain`, `tests/Unit/Brain/StorageDeviceNamingTest.php` (61 tests). **Storage-app side done 2026-09-26** — `w/storage_device/app/Support/DeviceNaming.php`, checked against the Brain: www's `StorageDeviceNaming` was run over all 31 rows of `tests/fixtures/naming-vectors.json` once and agrees (a one-off check, not yet a www test) |
| **P1** | Marker creation command and marker/mount check | **Done 2026-09-26 in the storage app** — `storage:init-device`, `DeviceVolume`, `ResolveDevice` ([05](05-storage-app.md) §9). Verified against a fake mount point only; never against a real mount |
| **P2** | DNS records and wildcard certificate in the lab; production records documented for the operator | **Blocked on [11](11-lab-and-rollout.md) P0** — the records point at the lab `storage` VM, which does not exist yet. The lab must also set `STORAGE_BASE_DOMAIN=members.appfactory.test1` in `_env/vagrant/.env`, or hostnames are built under the production domain |

"Never reused" (§1) is not enforceable in the helper; it is enforced by `brain_storage_devices.number` being unique and a device being retired, never deleted ([03](03-registry-and-brain-admin.md)).
