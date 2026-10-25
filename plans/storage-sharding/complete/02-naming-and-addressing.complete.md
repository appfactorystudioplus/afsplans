# 02-naming-and-addressing — Completion

**Status:** Partial. P0's Brain side is built and tested. Everything else in this step lives in
repositories or infrastructure that do not exist yet, so it is recorded as blocked rather than
attempted: the storage app's copy of the hostname rule and the marker/mount check (needs the
`storage_device` repository, step 05 P0), and the lab DNS records and wildcard certificate (needs
the lab `storage` VM, step 11 P0). Nothing was committed. Development mode: `vagrant`.

## Summary

`App\Services\Brain\Storage\StorageDeviceNaming` is now the single place in the Brain where a
device number becomes a name, mount path, filesystem label, marker file or hostname, and where a
`Host` header or `storageN` name becomes a number again. It implements the convention in
§1–§2 and §5 of the plan exactly:

| Thing | Result for `N = 1` |
|---|---|
| name | `storage1` |
| mount point | `/mnt/storage1` |
| filesystem label | `STORAGE1` |
| marker file / path | `.afs-storage-1` / `/mnt/storage1/.afs-storage-1` |
| hostname | `1.storage-device.{base_domain}` |

- **Numbers** are integers from 1 to 2 147 483 647 (the signed 32-bit `INT` the registry column
  will use). `0`, negatives and larger values throw from every builder.
- **Hostname parsing is strict**, because a storage server will use the same rule to decide which
  device a request addresses. It accepts an optional `:port`, one trailing dot and any letter case,
  and rejects leading zeros, signs, exponent forms, non-ASCII digits, another base domain, extra or
  missing labels, suffix and prefix tricks, whitespace, embedded NULs, paths and userinfo. The
  pattern is anchored so a trailing newline cannot slip past `$`, and the base domain is
  `preg_quote`d so its dots are literal.
- **`base_domain` is configuration**: `config('brain.storage.base_domain')`, default
  `members.appfactory.studio`, overridable with `STORAGE_BASE_DOMAIN`. It is validated as a plain
  lowercase DNS suffix (no scheme, port, path, wildcard, underscore or empty/over-long label).
- **`node1` / `node2` are refused by name** through `isLegacyNodeName()` (whitespace is trimmed,
  so `" node3 "` and `"node1\n"` are refused too — over-refusing is the safe direction for a check
  whose purpose is keeping storage off the legacy mounts). The registry validation in step 03 is
  where it will be called.

"Never reused" (§1) is deliberately not in the helper: a pure class cannot know which numbers
existed. It is enforced by the registry's unique `number` and by retiring, never deleting, a device
([03](../03-registry-and-brain-admin.md)), and the class documents that.

## Files changed

In the **www** repository (uncommitted; the working tree already held other uncommitted changes,
none touched):

- `app/Services/Brain/Storage/StorageDeviceNaming.php` — new.
- `config/brain.php` — a `storage.base_domain` key added at the end of the returned array.
- `tests/Unit/Brain/StorageDeviceNamingTest.php` — new, 61 tests / 146 assertions.

In this plan folder: `02-naming-and-addressing.md` (phase table with real status) and this file.

No `.env` file was edited.

## Verification steps

- `php -l` on all three PHP files: no syntax errors.
- `php vendor/bin/phpunit tests/Unit/Brain/StorageDeviceNamingTest.php` (PHP 8.4.0, PHPUnit 11.5.42):
  **OK (61 tests, 146 assertions)**.
- `php vendor/bin/phpunit tests/Unit/Brain tests/Unit/Database`: **OK (121 tests, 218 assertions)** —
  the neighbouring Brain unit tests and the migration-ownership guard still pass.
- `php artisan tinker --execute` calling `StorageDeviceNaming::fromConfig()->hostname(3)` in the
  booted app printed `3.storage-device.members.appfactory.studio`, so the config key is wired.
- One test failed on first run and was a wrong expectation, not a code defect: I had listed
  `"node1\n"` as *not* a legacy name, but the helper trims whitespace and refuses it, which is the
  intended behaviour. The test now asserts the intended behaviour and says why.

## Not done

- **Storage-app hostname parser and test cases** — the repository does not exist. When step 05 P0
  scaffolds it, the rule and the test data in `StorageDeviceNamingTest` are copied, not rewritten.
- **Marker creation and the mount/marker check** (P1) — storage-app commands; blocked on step 05 P0.
- **DNS records and the wildcard certificate** (P2) — need the lab `storage` VM's address; blocked on
  step 11 P0. Production records remain an operator task, listed in step 11 §3.
- **`STORAGE_BASE_DOMAIN` for the lab** — `_env/vagrant/.env` was not edited. Until it sets
  `members.appfactory.test1`, the lab builds hostnames under the production domain.

## Follow-up work

1. Call `isLegacyNodeName()` and `assertValidNumber()` from the registry's request validation in
   step 03 P1, and add a validation-level test that `node1`/`node2` are rejected in every storage field.
2. Copy the hostname rule and the test data into the storage app in step 05 P0, and keep the two test
   files in step — the same hazard cloud-storage had with its three name validators.
3. Set `STORAGE_BASE_DOMAIN` in the lab `.env` as part of step 11 P0.
