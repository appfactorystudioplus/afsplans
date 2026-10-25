# 12 — Storage server enrollment: completion

## Status

**Built and tested in code (P0–P3); not yet run on a real server.** Written 2026-10-11. Plan: [12-server-enrollment.md](../12-server-enrollment.md)
(its "As built" section has the phase table, the deviations and the acceptance results).

## Summary

A storage server no longer has a credential generated, shown once and pasted into a file, and the estate keys no longer need copying between
apps. Staff add a server by address; the storage app (or the Brain, over ssh, on **Connect**) enrolls it; the Brain hands the credential over once
and each side keeps its own copy in its own database. Members gets its per-server control keys from the Brain and creates, registers and rotates
its own token key pair. Storage apps report the disks they can see; staff **Adopt** one, and the app creates the marker itself.

## Files changed

**www (Brain)** — new: `StorageEnrollment`, `StorageAddressResolver`, `StorageConnector`, `StorageDiscovery`, `BrainStorageMembersSurfaceController`, models
`BrainStorageServerEvent` / `BrainStorageEnrollmentAttempt` / `BrainStorageDiscoveredDevice`, migration `2026_10_11_000001_add_enrollment_to_brain_storage_servers`,
`core/scripts/storage/afs-storage-agent`. Changed: `StorageRegistry`, `StorageKeyRegistry`, `StorageConfigBuilder`, `BrainStorageController`,
`BrainStorageSurfaceController`, `VerifyStorageServerSignature`, `BrainStorageServer`, `ServerLogin`, `DomainSettings`, `config/brain.php`, `routes/api.php`,
`routes/portal.php`, the Brain settings editor schema, and the admin screen `brain_storage.ts`.

**storage_device** — new: `Identity`, `EnrollServer`, `ServerStatus`, `DiscoverDevices`, `DeviceDiscovery`, `MarkerProvisioner`, migration `…000002_create_server_identity_table`.
Changed: `BrainClient`, `VerifyBrainSignature`, `DeviceConfig`, `MountProbe` / `StatMountProbe`, `PullConfig`, `AppServiceProvider`, `routes/console.php`, `.env.example`, README.

**members** — new: `ControlKeys`, `TokenKeys`, `StorageKeysSync`. Changed: `DeviceClient`, `TokenMinter`, `config/storage.php`, `routes/console.php`.

Tests added: `BrainStorageEnrollmentTest`, `BrainStorageConnectTest`, `BrainStorageMembersSurfaceTest`, `BrainStorageDiscoveryTest` (www); `EnrollmentTest`, `DiscoveryTest` (storage_device);
`ControlKeysTest`, `TokenKeysTest` (members). Existing storage tests were updated where the behaviour intentionally changed (a server has no credential at creation; a rotation of an
enrolled server is a request; no master means "ask the Brain", not "refuse").

## Verification

All on SQLite in memory — the configured MySQL (lab) was unreachable from the development PC.

| Suite | Result |
|---|---|
| www, ten `BrainStorage*` test files | 228 tests, 1 failure: `test_there_is_no_route_that_deletes_a_server_or_a_device` (a global "Origin header required" check now answers the unauthenticated DELETE with 403 instead of 404/405 — not caused by this step) |
| www, `DomainSettingsTest` + `MigrationOwnershipTest` | 15 pass (settings keys match the editor schema; the new migration is owned correctly) |
| storage_device, full suite | 516 tests, 1 failure: `OpsTest::test_untrash_moves_a_trashed_item_back_and_is_idempotent` (present before this work), 4 skipped |
| members, `tests/Feature/Storage` | 242 tests, 0 failures, 6 skipped |
| `brain_storage.ts` | type-checks with no errors in the file |
| `afs-storage-agent` | `bash -n` only; **never executed** (no `jq` on the development PC) |

## Not verified / follow-up

- Press **Connect** on a real storage server (the Brain pushes `afs-storage-agent` itself; nothing is installed) and check **E6/E7** for real. See `tmp/handoff-share-remote.md`.
- Apply the new migrations on the www15 database, set `storage_agent.*` in the Brain settings (`brain_url` has no default on purpose), and install the agent on the storage server.
- Per-device DNS through the Brain, deriving `internal_base_url`, and calling the storage app's `brain-app/refresh` remain unbuilt.
- The first token after a brand-new members install can be rejected for up to five minutes (the storage servers have not pulled the new public key yet).
- A server's ssh password is stored encrypted (like web servers'); there is no screen to clear it other than saving the server with an empty password.
