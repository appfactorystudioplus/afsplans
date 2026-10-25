# 02 — Current state, verified

## Completion document

When this plan step is completed, write `complete/02-current-state-audit.complete.md`
with status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (JSDoc/PHPDoc/`///`).

---

Everything below was read against the working trees on 2026-08-22. Line numbers are
accurate at that commit. Nothing here is inferred from documentation.

> **Scope note.** This document audits the **storage** code. The authentication defects it
> found are not confined to storage — most of the members API resolves its caller from a
> client-supplied query parameter. That wider finding, and its fix, is
> [01-server-authentication.md](01-server-authentication.md); S1–S3 below are the storage
> instance of it.

## 1. What exists

| Repo | Component | Lines | State |
|---|---|---|---|
| members | `app/Http/Controllers/StorageController.php` | 353 | The whole backend. Upload, list, download, delete. |
| members | `resources/js/controllers/StorageController.ts` | 833 | A working-ish file-manager UI: drop zone, folder traversal, XHR upload, hierarchy render. |
| members | `resources/js/controllers/CloudController.ts` | 116 | **Tab switcher only.** Renders four provider templates and toggles `active`. No storage logic whatsoever. |
| members | `resources/js/controllers/AFSController.ts` | 264 | Mock **server provisioning** (`localStorage`, fake regions/images). Nothing to do with files. |
| members | `resources/js/templates/views/cloud.html` | 296 | The Cloud Sync markup. `<div id="afs-content"></div>` is filled from `#afs-template`. |
| members | `database/migrations/2026_01_22_000000_create_user_files_table.php` | 32 | `user_files` — the only storage table. |
| auth | `public/checkpoint/checkpoint_registry_v3.json` | — | `resources.storage_files_size_kb` with five tiers. Complete and correct. |
| afsdesktop5 | Cloud tab in `src/renderer/html/sync.html` | — | `<h3>Cloud Sync</h3><p>Coming soon...</p>`. Explicitly out of scope in that repo's plan 08 §9.4. |
| afsmobile_flutter | — | 0 | **No storage code of any kind.** |

### 1.1 The naming collision worth knowing about up front

There are **two different things called "storage"** in the members frontend and they are
unrelated:

- `resources/js/controllers/StorageController.ts` — the file manager described above.
- `resources/js/forms/services/StorageService.ts` — `localStorage` persistence for
  FormSwift-style documents (`formswift_clone_documents`). Not file storage. Do not wire
  anything to it.

And the feature the member reaches through **Tools → Cloud Sync** is `CloudController` /
`cloud.html`, while the working upload UI is `StorageController` / `storage.html`. They
are separate views. Part of this plan's job is deciding which survives — see §5.

## 2. Defects — security

| # | Defect | Evidence | Severity |
|---|---|---|---|
| **S1** | **Every member is authenticated as the same hardcoded admin.** `getAuthenticatedUserId()` calls `$this->auth->admin()->logInAsUserByUsername("appfactorystudio")` and then returns `$this->auth->getUserId()`. That value is identical for every caller. Consequences: all uploads land in one storage root, `UserFile::where('user_id', $userId)` returns **every member's files to every member**, and `destroy()`/`download()` will happily act on any row. | `StorageController.php:35`, `:39`, `:65`, `:188`, `:281`, `:285` | **P0** |
| **S2** | **DB credentials read from `env()` at request time inside the controller**, building a raw PDO connection per call, bypassing Laravel's configured connection and its pooling. `env()` outside config files returns `null` once config is cached, so this also breaks under `php artisan config:cache`. | `StorageController.php:24–32` | **P0** |
| **S3** | **The `checkpoint.verify` middleware already solves S1 and is not used here.** Every other API in the app resolves the caller through it — `$request->attributes->set('member_reference_num', ...)`. The storage routes sit outside that group entirely. | `routes/web.php:62–65` vs `:82`, `VerifyCheckpointToken.php:65` | **P0** |
| **S4** | **Naive path sanitiser with no containment check.** `str_replace(['..', '\\'], ['', '/'], $folderPath)` then `ltrim($folderPath, '/')`. There is no `realpath` assertion that the final destination is inside the storage root, no absolute-path rejection, no control-character rejection, and no symlink check. The result is passed straight to `makeDirectory()` and `putFileAs()`. | `StorageController.php:203–215` | **P1** |
| **S5** | **No ownership check on the storage root.** The root is derived from the (broken) user id, so even after S1 is fixed, nothing asserts the resolved path belongs to the caller. | `StorageController.php:189`, `:286`, `:330` | **P1** |
| **S6** | **`max:5242880` is ambiguous and effectively unenforced.** Laravel's `file` rule takes **kilobytes**, so this is 5 GB — but `post_max_size`/`upload_max_filesize` will reject the request long before the validator runs, producing an empty `$_POST` and a confusing 422 rather than a clear limit error. The server build scripts that set these to 10G are commented out. | `StorageController.php:170`; `afsserver/docker/builds/initial_install.sh:60–62` | P2 |

**S1 is not theoretical.** It is a live cross-tenant data exposure in shipped code.

### 2.1 The same bug, outside storage

S1 is the storage instance of a pattern that runs through the app. Verified in the same
pass:

| File:line | Identity source | Effect |
|---|---|---|
| `MemberController.php:19` | `$request->query('user_id')` | Any caller reads any member's record |
| `AfsConnectSettingsController.php:172` | `query('user_id') ?? input('user_id')` | Any caller reads/writes any member's afs-connect settings |
| `Brain/DomainController.php:67`, `:276` | `query('member_reference_num') ?? session(...)` | Any caller lists any member's domains |

None of those routes sit inside the `checkpoint.verify` group (`routes/web.php:82–190`), so
they do not even require a token. `ResolvesMarketMember`'s own header comment names the
first two as "exactly the anti-pattern step 04 rules out" — and they still do it.

The fix for all of them, including S1–S3, is [01-server-authentication.md](01-server-authentication.md).
That step is a prerequisite for Phase 1 here rather than a hardening pass at the end, and
it is worth shipping independently of storage.

## 3. Defects — correctness

| # | Defect | Evidence |
|---|---|---|
| **C1** | **Filenames are destroyed on upload.** `generateUniqueFilename()` does `Str::slug($filename, '_')` then appends `uniqid()`. `Q4 Report (final).pdf` is stored as `q4-report-final_65f1a2b3c4d5e.pdf`. Unicode names slug to near-nothing or to the literal `file`. The original is kept in a DB column, so the *disk* becomes unreadable to any other tool — including a member SSHing in, and including sync, which can never match a local file to its remote twin. This is a direct contradiction of the "upload as-is" requirement. | `StorageController.php:185`, `:248–266` |
| **C2** | **Wrong storage root.** Code writes `/mnt/node2/users/{userId}/storage`. The target is `/mnt/node1/{member_reference_num}/storage/{bucket}`. Different mount, different key, and no bucket segment at all. The rest of the platform already uses `/mnt/node1/{systemuser}/…` (e.g. `dovecot` mail at `/mnt/node1/${systemuser}/mailbox`). | `StorageController.php:189`; `afsserver/afsdata/configfiles/dovecot_template/conf.d/10-mail.conf:34` |
| **C3** | **No duplicate detection.** Because `uniqid()` makes every filename unique, uploading the same folder twice produces two complete, independent copies with no warning — the exact silent error the requirement calls out. There is no unique index on `user_files` to catch it either. | `StorageController.php:263`; migration has no unique key |
| **C4** | **No filename validation of any kind.** Nothing checks NUL bytes, control characters, `/`, segment length against `NAME_MAX`, reserved Windows basenames, trailing dots/spaces, or UTF-8 validity. The slug in C1 accidentally hides some of this on the *file* name while leaving `folder_path` completely unchecked. | `StorageController.php:198–211` |
| **C5** | **The listing is built from the DB, never the disk.** `index()` reads `user_files` and constructs the hierarchy in PHP. Any drift — a failed rename, a manual delete, a partial upload — is invisible, permanently. There is no reconciliation. | `StorageController.php:65–74`, `:82–153` |
| **C6** | **Folders exist only as a string column.** `folder_path` on each file row. An empty folder cannot be represented, cannot be created, and disappears the moment its last file is deleted. Moving a folder means rewriting every descendant row. | migration `folder_path`; `buildFolderHierarchy()` |
| **C7** | **Upload is strictly sequential, one whole file per XHR, with no resume.** The client loops `for (let i = 0; i < files.length; i++)` and `await`s each. 10 000 files is 10 000 round trips, serialised. A drop at 99 % of a 10 GB file restarts at byte 0. | `StorageController.ts:463–565` |
| **C8** | **A failed file `alert()`s and continues.** No retry, no queue, no record. The loop swallows the failure and moves on, so a partially-uploaded folder looks finished. | `StorageController.ts:559–564` |
| **C9** | **`readEntries()` is called once per directory.** The `FileSystemDirectoryReader` API returns **at most 100 entries per call** and must be called repeatedly until it yields an empty array. Any dropped folder with more than 100 children silently uploads only the first 100. | `StorageController.ts:105–110` |
| **C10** | **`filePaths` map is keyed by `File` object and leaks.** Entries are deleted only on success (`:516`); every failed upload leaves a permanent `Map` entry holding a `File` reference. | `StorageController.ts:99`, `:516` |
| **C11** | **No progress aggregation.** The single progress bar is overwritten by each file's `onprogress`, so it repeatedly sweeps 0→100 % and conveys nothing about the overall job. | `StorageController.ts:506–511` |
| **C12** | **CSRF token discovered per request, warns and proceeds when missing.** If neither the meta tag nor a hidden input is present the request is sent anyway and fails with an opaque 419. | `StorageController.ts:492–501` |
| **C13** | **`Str::slug()` on a non-Latin filename can return an empty string**, falling back to the literal `file`, so a folder of Japanese-named documents becomes `file_<uniqid>.pdf` repeated N times. | `StorageController.php:255–261` |
| **C14** | **Delete removes the file but never its now-empty directories**, so the tree accumulates empty folders that the DB-driven listing cannot even show (C6). | `StorageController.php:294–300` |

## 4. Defects — the quota system does not exist

There is no storage quota code anywhere in members. What *does* exist, and what this plan
must connect to:

- `checkpoint_registry_v3.json → resources.storage_files_size_kb` is **complete and
  correct**: `scope: member`, `period: lifetime`, `overage: block`, `warn_at_pct: 85`,
  `unit: kilobytes`, and five tiers — `524288` (512 MB), `2097152` (2 GB), `10485760`
  (10 GB), `52428800` (50 GB), `-1` (unlimited).
- The tier index is `brain_memberships.membership`, passed through as a bare integer,
  clamped 0–4, missing ⇒ 0, **never resolved by name**
  ([afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.3).
- `alloc_v2` at `/checkpoint/alloc/v2/*` is the platform metering engine, but
  **`AllocationV2Manager.php` is implemented in no app** (ibid. §11.4), and Parse's
  `MemberRegistry` class-level permissions are **currently public**, so stored usage
  numbers are untrusted for anything security-bearing (ibid.).

That last point drives decision **D4**: the authoritative byte count for enforcement lives
in the members DB, not in Parse. See [04-database-schema.md](04-database-schema.md) §3.

## 5. The two-views problem

The requirement names **Tools → Cloud Sync** (`CloudController.ts`) as where a member
accesses their data. But the only working upload UI is in `StorageController.ts`
(`storage.html`), a different view.

**Resolution:** the AFS provider pane inside Cloud Sync becomes the single file-manager
surface. `StorageController.ts`'s useful parts — drop-zone handling, folder tree walk,
hierarchy rendering — are extracted into a reusable module that the Cloud Sync AFS pane
mounts, and the standalone `storage.html` view is retired. Details in
[09-web-client.md](09-web-client.md) §2. This avoids maintaining two file managers and
avoids the alternative of leaving Cloud Sync as a shell that points at a second view.

## 6. What is worth keeping

Not everything is wrong. These are sound and should be carried forward rather than
re-invented:

- **The recursive `webkitGetAsEntry()` folder walk** (`StorageController.ts:93–130`) is
  the right approach for drag-and-drop folder upload; it needs the C9 pagination fix and
  an iteration limit, not a rewrite.
- **`buildFolderHierarchy()` / `getOrCreateFolder()`** (`StorageController.php:82–153`) is
  a correct path-map tree builder. It should be fed from the new file/folder tables
  instead of the flat `user_files` list.
- **XHR-with-`upload.onprogress`** is still the only way to get upload progress in a
  browser (`fetch` has no upload progress). Keep the technique, replace what it sends.
- **`checkpoint.verify` + `member_reference_num`** is the correct, already-deployed
  identity chain. Storage simply needs to join the group it is currently outside of.
- **`afsdesktop5/src/main/sync/`** is a complete, tested implementation of the session /
  manifest / ranged-PUT / commit state machine this plan needs — including a hardened
  path validator, `.part` staging, an authoritative-offset resume model, and a job
  registry with throttled progress. The desktop cloud client should be a port of it, and
  its `paths.ts` validator is the reference implementation for
  [06-name-validation.md](06-name-validation.md).

## 7. Migration liability

`user_files` rows point at slugged filenames under `/mnt/node2/users/{sharedAdminId}/storage`.
Because of S1 those rows are **commingled across members with no way to attribute them**
— `user_id` is the same value for everyone.

This is stated plainly because it constrains the migration: there is no reliable
programmatic path from the existing data to per-member buckets. [13-rollout-and-testing.md](13-rollout-and-testing.md)
§3 specifies the manual, operator-driven reconciliation this requires, and why an
automatic migration would be guesswork.
