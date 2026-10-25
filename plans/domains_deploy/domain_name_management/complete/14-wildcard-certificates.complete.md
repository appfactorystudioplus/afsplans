# 14 — Wildcard certificates and the tenant's HTTPS: completion report

**Plan file:** [`../14-wildcard-certificates.md`](../14-wildcard-certificates.md)
**Date:** 2026-10-24
**Status:** ✅ P0 and P1 built and tested. The sites wildcard was **issued** on the dev server; it was **not yet confirmed on the host**, the storage wildcard has not been issued, and a real tenant build with the new nginx scripts has not run. Nothing is committed.

## 1. What was built

| Plan section | Built as (`w/www` unless noted) |
|---|---|
| §14.2 one Let's Encrypt | `ProcessCertbotClient` no longer passes a staging server; `SitesCertState::certName` is one lineage; the `staging` and `enabled` values, the staging branches of `SitesCertRenewer`, `sites-cert:distribute` and `sites-cert:status`, and the `staging` test are removed. `SitesCertSettings` reads only `tenant_cert` and `tenant_cert_key` from `controller.letsencrypt_cert_management`; everything else is in `value()`. The editor schema and the seed hold only that pair. |
| §14.2 `--domain` | New `SitesCertDomains` (`all()`, `selected()`). `SitesCertSettings::fromController($controller, ?string $domain)` and `load(?string $domain)`: a domain other than the sites zone gets `certPath` `/etc/letsencrypt/live/<domain>/fullchain.pem`, `keyPath` `.../privkey.pem`, no `tenant_cert` needed, and is checked as a host name. `SitesCertState`: lineage `sites-wildcard` or the domain, record `state.json` or `state-<domain>.json`. `RemoteScripts::addChallenge` writes `_acme-challenge.<domain>. 60 IN TXT "..." ; afs-acme`. `sites-cert:renew`, `:distribute`, `:status` and `:challenge` take `--domain=`; without it they loop over `SitesCertDomains::all()` (the sites zone, and `storage-device.<brain.storage.base_domain>` when set). |
| §14.2 certbot starts for real | `ProcessCertbotClient`: the hook is two plain unquoted paths (`artisan sites-cert:challenge add\|remove --domain=<domain>`), the php is found with `PhpExecutableFinder` (under php-fpm `PHP_BINARY` is `php-fpm`), and a path with unusual characters stops the run with a message. |
| §14.3 tenant HTTPS (`w/members`) | `build_checks.sh`: `add_tls_to_server_conf FILE CERT KEY [LE_DIR]` adds the certbot `include` and `ssl_dhparam` when those files exist (`LE_DIR` is checked as a plain path); new `https_redirect_block FILE DOMAIN`. `app_install.sh`: after the 443 file is accepted, the sites name's port 80 block is replaced by the redirect, as its own install with `.prev`, `check_web_configs`, and a restore with a warning if refused. |
| Documentation | `plans/_docs/reports/letsencrypt_documentation.md` (what it does, every command as a copyable block, first-time setup, problems and fixes, where things are, the tenant nginx files). `old_way/complete/05-certificates.complete.md` has a dated update. `complete/00_extras.md` E20 and E21. |

## 2. Verified

* `SitesCertTest`: **18 tests, all pass**, on a local MariaDB (certbot and ssh faked): single lineage in every environment; another domain's settings, paths, lineage and challenge line; refused domain names; `SitesCertDomains`; the hook as two unquoted paths. `tests/Feature/Brain/Domains` (**282 tests**) also passes; the 21 skipped tests need real VMs.
* **On the dev server, `sites-cert:renew --dry-run` (as `apache`)** listed both wildcards with the right install path for each; the sites one was held with 89 days left.
* **The first real issue ran on the dev server and Let's Encrypt issued the certificate** for `*.sites.appfactory.studio` (it ends 2027-01-22). The challenge line was published, answered and removed by the run itself.
* **The nginx scripts:** both functions run in bash on this PC (a missing block, a bad name and a bad folder fail without changing the file). The generated 443 block (with the real `/etc/letsencrypt/options-ssl-nginx.conf` and `ssl-dhparams.pem`) and the redirect block pass `nginx -t` in a throwaway sandbox on the dev server.

## 3. What went wrong on the first real runs, and the fix

| Said | Cause | Fix |
|---|---|---|
| `cannot create snap home dir: mkdir /usr/share/httpd/snap …` (three times) | certbot is a snap; it takes the user's home from `/etc/passwd`, not `$HOME`. The first fix, setting `HOME` for the process, did nothing and was reverted. | Server: a home under `/home` for `apache` (`usermod` needed the web services stopped; the first attempt only created the folder). |
| `Unable to find manual-auth-hook command '/usr/bin/php'` | The hook was quoted; certbot compares the first word with the quotes. | Unquoted paths; `PhpExecutableFinder`. |
| `FAILED on mk7r2dwvaz1: … Permission denied` after "Let's Encrypt issued the certificate" | The web server row's stored ssh password was stale (the DNS row for the same machine was right). | The owner corrected it. |
| `the web server refused the new certificate; the previous one is back` | `nginx -t` failed for an unrelated reason: a block in `appfactory.studio.dev.zone.conf` pointed at the never-issued storage wildcard. The script's rollback was correct. | Issue the storage wildcard (`--domain`, added for this), then distribute. |

## 4. Mistakes of mine, fixed

* A `git checkout` used to undo my `HOME` change also reverted the (uncommitted) staging removal in two files; it was redone and the tests re-run before anything was uploaded again.
* The first `https_redirect_block` and `add_tls_to_server_conf` runs on this PC used a Windows path with backslashes inside a `sed` replacement; the path argument is now checked as a plain path.

## 5. Not done

1. **The sites wildcard on the host.** After the two failures above, the owner had not yet reported `sites-cert:distribute` succeeding.
2. **The storage wildcard** (`--domain=storage-device.members15.appfactory.studio`) has not been issued. It is what lets `nginx -t` pass again.
3. **The nginx scripts are not uploaded** to the dev server's members project and no tenant was built with them. Two cases were added to `tests/Feature/BuildChecksShellTest.php`; they need `/bin/bash`, so they have **not been run** on this PC.
4. Sending a wildcard to a separate storage server; a certificate for a member's own domain (plan 05 §5.2).
