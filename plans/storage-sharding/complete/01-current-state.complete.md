# 01-current-state — Completion

**Status:** Complete. Phase P0 (verify the document against the tree) is done. The verification
found five errors in the plan as first written; all are corrected in the plan files listed below.
No code was written or changed anywhere; this step only reads the tree and edits plan documents.

Development mode when run: `vagrant` (read from `plans/DEVELOPMENT_MODE.md`), so the lab's files
and the checkouts under `C:\projects\vagrant\afs-eco` were readable. Nothing was run against a
database, a VM, or a running service.

## Summary

Every claim in `01-current-state.md` was checked by reading the file or searching for the string.
Confirmed as written: the single mount constant, the `members_storage_*` table names and their
single www-owned migration, the absence of a `device` column, the Brain server/DNS registry
precedent, the lab's three VMs and `NODE1`/`NODE2` disks, and the absence of storage DNS records.

Found wrong or incomplete, and fixed:

1. **Quota and retention are not where the plan said.** They are `App\Checkpoint\Storage\
   {QuotaService, RetentionService, StorageRetentionAdapter}`, sitting on a **shared**
   `App\Checkpoint\RetentionEngine` that email retention also uses. The filesystem operations
   (`rename` to/from `.trash`, `rrmdir`, staging removal, `disk_free_space`) are in the adapter
   and `RetentionService`. Step 10 now names them correctly and forbids touching the engine or
   the email adapter.
2. **`brain-client` does not provide `Brain::report()` or a Brain→app receiver.** Only settings,
   mail, manifest, signed pull with ETag/last-known-good, a circuit breaker, and
   `Signature::sign` exist; `www_brain` step 10 still lists report/push as planned. Step 05 now
   has the storage app implement its two `brain-app/` routes itself, and step 07 no longer
   depends on an app-events store — it uses a new `brain_storage_device_events` table.
3. **The per-server credential cannot be stored hashed.** An HMAC verifier needs the secret. Steps
   03 and 08 now store it encrypted, keep the previous secret for rotation overlap (as the existing
   `key`/`key_next` do), and state the trade-off: a compromised Brain database plus application
   key exposes every server secret.
4. **The Brain's signature middleware does not fit a per-server credential.**
   `VerifyBrainSurfaceSignature` keys on `X-Brain-App` and `.env`. Step 03 gains a §6 specifying a
   sibling `brain.storage` middleware, keyed on `X-Brain-Server` and the registry row, reusing
   `BrainSurfaceSignature::verify()` and the same skew/nonce rules, with two new routes under the
   existing `internal/brain` group.
5. **Recordings are not an existing storage writer.** The `storage_ref` column exists; no code in
   `communications`, `members/app`, `members/resources/js` or `www/app` writes it. Recorded as a
   future consumer that must go through placement.

Also recorded, newly found: `members/app/Http/Controllers/Brain/DomainController.php` writes to
`/mnt/node1/appfactorystudio/tmp`, direct application-code evidence for reserving `node1`; and
`members/tests/Concerns/CreatesStorageTestSchema.php` hard-codes the storage table columns, so
adding `device` must update it in the same change.

## Files changed

All under `packages/afsserver/plans/storage-sharding/` (plan documents only):

- `01-current-state.md` — rewritten with the verified facts and a corrections table (§6).
- `03-registry-and-brain-admin.md` — encrypted credential columns, `brain_storage_device_events`,
  new §6 (the `brain.storage` middleware and routes), P1 wording.
- `05-storage-app.md` — the Brain surface row.
- `07-health-polling-and-reporting.md` — transitions go to `brain_storage_device_events`.
- `08-security.md` — credential storage and the stated trade-off.
- `10-members-and-client-changes.md` — real class locations and the shared-engine constraint.
- `complete/01-current-state.complete.md` — this file (creates `complete/`).

No file outside this plan folder was modified. Nothing was committed.

## Verification steps

- Read `plans/DEVELOPMENT_MODE.md` (`vagrant`) before touching anything.
- `grep` for `/mnt/` across `members/app`, `routes`, `config` and `resources/js/storage`: five
  hits — `PathResolver` (constant and comment), `StorageRetentionAdapter` (comment),
  `DomainController` (two `/mnt/node1/appfactorystudio/tmp` uses).
- Read each touch-point method's location by `grep -n` in the three storage controllers, the
  session sweep, `PathResolver`, `RetentionService`, `StorageRetentionAdapter`; confirmed the line
  numbers cited in §1.
- Confirmed each model's `$table` and that only
  `www/database/migrations/2026_08_23_000001_create_members_storage_tables.php` creates the five
  storage tables; the other migrations that mention `members_storage_nodes` only reference it.
- Searched `w/` for `storage_ref` / `members_storage_nodes`: only the members storage code, its
  test-schema helper, and the www migrations.
- Read `brain-client`'s `BrainClient` public methods, `Signature`, and facade docblock; read
  `VerifyBrainSurfaceSignature` and `routes/api.php`'s `internal/brain` group in full.
- Listed the lab: `dns/` contains `tenants.zone` and `test1.zone` with no storage records;
  `Vagrantfile` defines `dns`, `web`, `web2` only; `mount-node-storage` is a named provisioner.
- Confirmed the client repos: `C:\projects\vagrant\afs-eco\afsmobile_flutter` contains
  `lib/repositories/storage_repository.dart` and `lib/services/upload_engine.dart`;
  `C:\projects\vagrant\afs-eco\afsdesktop5\src\main\cloud` contains the desktop cloud client.
  (`C:\projects\afs-eco\afsmobile_flutter` also exists but holds no `lib/`; it is not the checkout.)
- Re-ran a relative-link check over every `.md` in the plan folder: no broken links.

## Not verified

- Other `w/` repositories were not searched for `/mnt/node1` beyond `members` — the estate-wide
  search is slow on this tree and a first attempt timed out. The storage-specific searches
  (`storage_ref`, `members_storage_nodes`) did cover all of `w/`.
- `afsserver/commands/commands.sh`'s use of `node1`/`node2` is taken from
  `AFS-DEPLOYMENT-VAGRANT.md` §6; the script itself was not re-read.
- Nothing was executed: no database query, no VM check, no request to a running service.

## Follow-up work

1. Repeat the bounded `/mnt/node1` search in each repository the first time a later step touches
   it (noted in `01-current-state.md` §3).
2. Reconcile step 10's client-file names against the current mobile and desktop trees when
   client work starts — the mobile tree now also contains `network_service.dart` and
   communications files that were not read here.
3. Decide whether the `brain-app/` receiver should be built once in `afs/brain-client` for every
   app (`www_brain` 10 Q2–Q4) rather than a second time inside the storage app; step 05 currently
   builds only the two routes it needs.
