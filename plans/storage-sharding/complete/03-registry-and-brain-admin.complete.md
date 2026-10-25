# 03-registry-and-brain-admin — Completion

**Status:** Done for the Brain (backend P0, P1, P3 and the P2 UI code), with the gaps listed under
"Not verified". Nothing was committed. Development mode: `vagrant`. Every change landed in the
**www** repo (`C:\projects\vagrant\afs-eco\w\www`); no code was written to afsserver.

## Summary

Staff can now register storage servers and devices in the Brain, and a storage server has a signed
surface to pull its config from and push reports to.

- **Schema** (`2026_09_27_000002_create_brain_storage_tables.php`): `brain_storage_servers`,
  `brain_storage_devices`, `brain_storage_device_reports` (append-only history) and
  `brain_storage_device_events` (audit trail). Explicit index/FK names, `hasTable` guards. Device
  `number` is UNIQUE and the FK to servers is `restrict`; there are no delete routes, only
  `retired`, so numbers are never reused.
- **`StorageRegistry`**: create/update servers, rotate the per-server credential (shown once,
  stored encrypted, previous key kept for the rotation grace), create devices as `pending`,
  update devices under a transition map (retired is terminal), capacity/watermark validation,
  refuses `node1`/`node2` labels, static SSRF checks on `internal_base_url`. Every state change
  writes an event row with the actor.
- **`brain.storage` middleware** (`VerifyStorageServerSignature`): HMAC per server via
  `X-Brain-Server`, ±300 s skew, nonce replay guard, bare 401 on any failure.
- **Surface** (`/internal/brain/storage`): `GET config` (ETag/304) and `POST report`
  (idempotent per device by `reported_at`; a device the caller doesn't host is a bare 403; future
  `measured_at` is 422).
- **Staff API** (`/portal/api/v1/brain/storage/...`): fleet, create/update server, rotate
  credential, create/show/update device. Non-staff get 404, unauthenticated 401.
- **`brain:storage-prune`**: deletes report history past `report_retention_days` (30), daily,
  Brain-only; never touches devices or events.
- **UI**: `brain_storage.ts` (fleet + device views, one-time credential alert with copy button),
  wired through `brainView.ts`, `imp_brain.ts` and a Storage button in `brain_content.html`.

## Verification

| Check | Result |
|---|---|
| `BrainStorageSurfaceTest` | 26 pass |
| `BrainStorageStaffTest` | 54 pass |
| `BrainStoragePruneTest` | 4 pass |
| `StorageDeviceNamingTest` (step 02) | 61 pass |
| `MigrationOwnershipTest` | passes with the new migration |
| `npx tsc --noEmit` | exit 0; the three new/edited TS files confirmed in `--listFiles` |
| Full www suite | 866 tests, 1 error + 10 failures — see below |

The 11 full-suite failures are not caused by this step. The captured output contains no
mention of "storage", and the tests are: `FormsExternalSdkStaticTest`,
`BuilderModuleRefsBackfillTest` (PHP↔TS module-registry mapping), five Appointments/Ecommerce/
Events receipt tests (mailable not sent), and three `ModulePagePathsTest` cases (expects
`https://www.test`, environment gives `https://www.appfactory.test1`). I did not touch any of
those areas, and I did not run the suite without my changes to prove they were failing before,
so "pre-existing" is inferred, not measured.

## Not verified

- The Vite build was not run and the UI was never opened in a browser; only `tsc` is clean.
- Health is not derived yet: a device's `health` stays `unknown` until step 07. The ingest stores
  reports but deliberately does not decide health.
- No placement panel or capacity trend chart in the UI (step 04 / later).
- The lab `.env` still needs `STORAGE_BASE_DOMAIN=members.appfactory.test1` (not edited).
- Nothing exercised the surface from a real storage server; that needs step 05.
