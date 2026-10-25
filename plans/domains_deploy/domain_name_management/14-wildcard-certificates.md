# 14 — Wildcard certificates and the tenant's HTTPS

> **STATUS 2026-10-24: BUILT (P0, P1). The sites wildcard was issued on the dev server; sending it to the host, the storage wildcard and a real tenant build are still to run.** What exists, what was checked and what went wrong: [complete/14-wildcard-certificates.complete.md](complete/14-wildcard-certificates.complete.md). How to run it, command by command: `../../_docs/reports/letsencrypt_documentation.md`. This step finishes the certificate half of [old_way/05-certificates.md](../old_way/05-certificates.md) (§5.1: issue, renew, distribute) and follows [13](13-turning-it-on.md), which removed the domain settings.

## 14.1 The problems

1. **The wildcard had never been issued.** The run (certbot with a DNS-01 challenge, then a push to the web hosts) had only been tested with fakes. It also carried a Let's Encrypt *staging* mode that followed `VITE_SUBDOMAIN`, an `enabled` switch, and a group of settings for things that never change.
2. **The storage devices need a wildcard too:** `*.storage-device.<STORAGE_BASE_DOMAIN>` (storage-sharding 02 §3, 08 §8). The dev nginx file already had a block pointing at that certificate; it did not exist, so `nginx -t` failed for the whole server and every nginx reload, ours and every tenant build's, would have failed.
3. **The tenant's HTTPS block was bare** (no certbot TLS settings), and plain HTTP for the sites name was never sent to HTTPS.

## 14.2 P0 — the wildcard run

* **One Let's Encrypt.** No staging, no development or production certificate, no `enabled` switch (owner, 2026-10-24: everything is production for certificates because it has to just work). The only certificate settings are `controller.letsencrypt_cert_management.tenant_cert` and `tenant_cert_key`; the zone, e-mail, commands, paths and timings are built in.
* **More than one wildcard.** `sites-cert:renew`, `:distribute`, `:status` and the certbot hook `:challenge` take `--domain=<base domain>` (`*.<domain>`). Without it they do every wildcard the platform keeps (`SitesCertDomains::all()`: the sites zone, and `storage-device.<STORAGE_BASE_DOMAIN>` when that is set). The sites zone keeps `tenant_cert` / `tenant_cert_key`; any other domain is installed on the web hosts at `/etc/letsencrypt/live/<domain>/fullchain.pem` and `privkey.pem`, where nginx and every Let's Encrypt tool look.
* **The challenge line is fully qualified** (`_acme-challenge.<domain>. 60 IN TXT "..." ; afs-acme`), so one include file serves any name under the zone. Each domain has its own certbot lineage and its own `state-<domain>.json`.
* **Make certbot start on the real server.** `certbot` there is a snap: the web user's home in `/etc/passwd` must be under `/home` (a one-time server change, `usermod -d /home/apache-home apache` with the web services stopped), and the hook is given to certbot as two plain unquoted paths with the command-line `php`, not `php-fpm`.

## 14.3 P1 — the tenant's nginx (old way, members)

In `build_checks.sh` and `app_install.sh`:

* `add_tls_to_server_conf FILE CERT KEY [LE_DIR]` adds `include /etc/letsencrypt/options-ssl-nginx.conf;` and `ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;` to the 443 block when those files exist on the host.
* `https_redirect_block FILE DOMAIN` turns the port 80 block of the sites name into `listen 80; return 301 https://$host$request_uri;`. It is applied only after the 443 file passed `nginx -t` and `apachectl -t`, as its own install with the previous file kept; a refused redirect is put back with a warning and the name stays reachable on HTTP. The member's own `www.<domain>` block is not touched.

## 14.4 Decisions

| # | Question | Answer |
|---|---|---|
| D34 | Is there a Let's Encrypt staging or an environment split for certificates? | **No** (owner, 2026-10-24; E20). |
| D35 | Where does a second wildcard go on the host? | `/etc/letsencrypt/live/<domain>/{fullchain,privkey}.pem`, where the existing nginx block expects it (owner, 2026-10-24; E21). |
| D36 | Does plain HTTP for the sites name redirect, and does the 443 block get certbot's TLS settings? | **Yes to both** (owner, 2026-10-24). |

## 14.5 Not part of this step

* A certificate for a **member's own domain** (plan 05 §5.2): it needs an active claim, a live site, the name resolving to the host and a webroot challenge. Not built.
* Sending a wildcard to a **separate storage server** (`brain_storage_servers`). On dev it is the same machine as the web host.
