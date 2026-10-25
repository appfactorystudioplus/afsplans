# 05-certificates — completion record

**Plan file:** [`../05-certificates.md`](../05-certificates.md)
**Repos:** `w/members`, `w/www` (settings schema)
**Date:** 2026-10-22
**Status:** 🟡 P0 (serving the sites name with the wildcard) and, since OD4 was answered (option A, owner, 2026-10-22), the **issue / renew / distribute run** are in code and tested. Not built: the member-domain certificate job and P2. **No certificate has been requested from Let's Encrypt, nothing is deployed to dev, nothing is committed.**

## §5.1 The sites name: one wildcard — P0

* **Settings.** Two Brain settings, `tenant_cert` and `tenant_cert_key` (the file paths **on the tenant host**), inside the property `controller.letsencrypt_cert_management` (moved there on 2026-10-23 by the owner's instruction; they were directly under `controller`), added to the settings schema `brain_controller_schema.ts`. Members passes them in the job as `tls.cert` / `tls.key` only when both are plain absolute paths (`DomainController::tenantTls()`); anything else becomes "none configured".
* **The HTTPS server.** After the vhosts are installed, `app_install.sh` checks the pair on the host (`tls_pair_state`: `unset` / `missing` / `ok`). With `ok` it renders the sites name's nginx file with the same generator, adds `listen 443 ssl` and the certificate and key after `server_name` (`add_tls_to_server_conf`), installs it as `<user>.tls.nginx.conf`, runs `apachectl -t` / `nginx -t`, and **puts the previous file back and fails the build** if either refuses. Same safe-write as the other site files.
* **No pair, or a file missing:** the sites name is served on plain HTTP and the log says `WARNING: …` (a warning line, not a progress step, so the 17-step count is unchanged and the build does not fail).
* **certbot.** With the wildcard in use there is no per-name certbot (`The wildcard covers …`). **Deviation:** when no wildcard is placed on a host, the old per-name certbot stays as the fallback (still gated by `LETSENCRYPT_CHECK`). The plan says to stop using it; doing so now would leave dev (no wildcard file; `LETSENCRYPT_CHECK=true`) with HTTP-only sites names. Removing the fallback is one `elif` once a wildcard exists on every host — your call.
* `LETSENCRYPT_CHECK` keeps its meaning and still gates the fallback; the member-domain job (§5.2) is not built.
* The wildcard files are placed on a tenant host once, at server setup. Dev has no `/etc/nginx/certs` today; production's path is yours to name (step 07, question 3).

## §5.2 The member's own domain — deliberately not enabled

The commented `RunAFSDNSCheck` call in `app_install.sh` now carries the five-part gate from the plan (active claim with accepted proof — NS delegation alone is not proof; a live website on this server; the apex and `www.<apex>` resolving here; `LETSENCRYPT_CHECK=true`; no valid certificate already covering both names) and says it belongs in its own job after the build. **Consequence to know:** the member-domain site (`www`, `APP_URL=https://www15.<domain>` on dev) is built with an `https://` address and **no HTTPS listener**, so it does not work in a browser until that job exists.

## §5.3 Testing — what proves what

| What | Result |
|---|---|
| The `:443` block and the restore on a bad file | `nginx -t` accepted the generated block (`listen 443 ssl`, certificate and key lines, the `/portal/api` location) on **dev's real nginx**, with a self-signed pair in `/tmp` and a throwaway config; nothing was reloaded or installed. The restore path (`install_conf` / `restore_conf`) is the one the other site files already use and are tested by `BuildChecksShellTest`. |
| `tls_pair_state` and `add_tls_to_server_conf` | Run directly in bash: unset, `null`, missing, empty file, ok; the block gets the three lines after `server_name`; a path containing `;` is refused. Test added to `BuildChecksShellTest` (cannot run on this Windows PC — `/bin/bash`; run where it exists). |
| `tenantTls()` | `DomainBrainSurfaceTest` (+1): valid pair passes; empty, half pair, relative path, `;`, space, newline, array, null all become "none". 25 tests, 110 assertions, OK. |
| Real issuance, HTTP-01 and the wildcard DNS-01 | **Unverified** — needs a publicly resolving name and Let's Encrypt staging (plan §5.3). |

## OD4 option A: the wildcard run (built 2026-10-22, `w/www`)

Once a day the Brain checks the certificate it holds. When it has `renew_days` or fewer left it asks Let's Encrypt for `*.<sites zone>` with certbot (manual DNS-01), publishes the challenge on every active DNS server, removes it, and puts the new pair on every active tenant host.

| Part | What it is |
|---|---|
| `SitesCertSettings` | All settings come from the Brain settings document, `controller.letsencrypt_cert_management.sites_cert` (schema added to the settings editor; the old flat layout directly under `controller` is no longer read). **Every value is required and none has a default**; a missing or unusable one stops the run and is named. The target paths on a host are `tenant_cert` and `tenant_cert_key` in the same property, shared with the tenant build, so there is one answer to "where is the wildcard on a host". |
| `RemoteScripts` | The three shell scripts sent to servers, as text. **Challenge:** one tagged line (`; afs-acme`) added to the include file the tenant builds already append to, then the name server reloads and is asked until it answers with the value (exit 5 if it never does). The Brain's own DNS engine is not used, because it rewrites include files in full and would erase the tenants' lines. **Remove:** every tagged line, nothing else. **Install:** checks that the certificate reads and has more than a day left and that the key belongs to it, writes beside the target and moves it in, runs the web server test before reloading, and puts the previous pair back if the test refuses it. |
| `DnsChallenge`, `CertDistributor` | Run those scripts over the Brain's ssh runner on each active DNS server and each active `Head` tenant host, with the login on that server's own row. The certificate and key go on standard input, never on a command line. One host failing does not stop the others. |
| `ProcessCertbotClient` | certbot with `--manual --preferred-challenges dns`, hooks `php artisan sites-cert:challenge add` and `remove`, and its config, work and log folders inside `work_dir` (no root needed). With `staging` on it uses Let's Encrypt's staging service and a separate lineage, **and a staging certificate is never sent to any server.** |
| `SitesCertRenewer` | The decisions of one run. Not due and every host has it: nothing. Due, or `--force`: clear leftovers, certbot, clear again, send to all hosts. A host that missed the pair, or was added since, is sent to without asking Let's Encrypt again. Records `state.json` (issued, not_after, per-host result, last error). Raises `Log::critical` at `alert_days` or fewer, or when no certificate is held. |
| Commands | `sites-cert:renew [--force] [--dry-run]`, scheduled daily at 04:10 in members through `brain:run` (the endpoint allowlist has it, with a 30-minute limit). `sites-cert:distribute [--server=REF]` for a new host before its first tenant. `sites-cert:status`, which exits 1 when the certificate is missing, at the alarm level, or the last run failed. `sites-cert:challenge`, certbot's hook, which refuses any name but the sites zone. |

### How it was checked

* `w/www` `SitesCertTest`: **15 tests, all pass**, using a fake ssh transport, a fake certbot and real openssl-made certificates. It covers every setting required and named; injection-shaped values refused; only Let's Encrypt's token shape reaching a script; the pair on stdin and not in the command; a non-PEM never sent; a certificate that is not due left alone; a due one cleared, issued and sent to each host; staging sending nowhere; a certbot failure sending nothing, cleaning DNS and ringing the alarm only when low; one host failing without stopping the others and being retried alone next day; a host added later served without a renewal; no DNS server stopping before certbot; a dry run contacting nobody; a server with no stored login reported. `BrainCommandsEndpointTest` (10 tests) still passes with its allowlist updated.
* **The shell scripts were run for real on the dev server** (`remote-run.sh`, a `/tmp/s05x` sandbox with a copy of an include file, a self-signed pair and a throwaway nginx config; real `sh`, `sed`, `awk`, `openssl`, `dig` and `nginx -t`; reload commands `true`): **30 of 30 checks passed** and the sandbox was deleted. `add` fails with exit 5 and a reason when the name server never shows the value, leaves the tenant lines alone, keeps one line per value however often it runs, and succeeds as soon as the name server shows it. `remove` returns the file to what it was and is safe to repeat. `install` places the pair (644 and 600), keeps a `.prev`, restores the previous pair and exits 7 when the web server refuses it, and exits 6 and changes nothing for a key that is not the certificate's, a certificate ending within a day, or nothing arriving.
* The four commands are registered (`php artisan list`).

### Settings (Brain settings editor, under `controller.letsencrypt_cert_management`; already in the seed row of `core/config/testdb_appfactorystudio.data-only.sql`, switched off and on staging)

```
"letsencrypt_cert_management": {
"tenant_cert": "/etc/nginx/certs/tenants.pem", "tenant_cert_key": "/etc/nginx/certs/tenants-key.pem",
"sites_cert": { "enabled": true, "staging": true, "email": "<your contact address>", "zone": "sites.appfactory.studio",
  "certbot_path": "/usr/bin/certbot", "work_dir": "<a folder the web user can write, e.g. /home/appfactorystudio/sites-cert>",
  "dns_include_file": "/var/named/zones/appfactory.studio.include.zone", "dns_reload_command": "systemctl reload named",
  "web_test_command": "nginx -t", "web_reload_command": "systemctl reload nginx",
  "ssh_runner": "/home/appfactorystudio/websites/dev/www/core/scripts/ssh/afs-ssh-run", "ssh_port": 22, "ssh_options": [], "ssh_timeout_seconds": 120,
  "renew_days": 30, "alert_days": 14, "propagation_wait_seconds": 120 }
}
```

**Superseded 2026-10-24 (domain_name_management E20): there is no `staging` and no `enabled`, and only `tenant_cert` / `tenant_cert_key` are settings; the rest of this block is built in.** The original text follows. Start with `staging: true`. The run then issues a test certificate, publishes and removes the real challenge line on the dev DNS server, and sends nothing to any host. When that works, set `staging: false` and run `php artisan sites-cert:renew --force`.

### Not proven yet (needs the first live run)

* certbot itself, and Let's Encrypt reaching the challenge on the real name servers. If a zone has secondary name servers that copy by transfer, the include file's change does not reach them until the zone's serial moves (the old tenant build has the same property), and `propagation_wait_seconds` only waits on the server that was edited. Dev has one DNS server, which is also the primary. Production's must be checked.
* The Brain's ssh runner (`afs-ssh-run`, bash and expect) started by the web user, and certbot and its hook running as that user. A first staging run settles both.
* Tenants already built keep their own certbot certificates; only new builds, and a rebuild, use the wildcard path.

## Not built

* **P1** member-domain certificate job and its gate — waits for a real test domain and for domain ownership proof in the old flow (set aside, step 06).
* **P2** certificate-expiry list for staff.
* Per-tenant certbot on a host without the wildcard is unchanged, so the Let's Encrypt per-registered-domain limit (OF14) still applies there.

## Update 2026-10-24 — the HTTPS block and the redirect

Two changes to what `app_install.sh` writes for the sites name (`<ref>.sites.appfactory.studio`), both from `core/scripts/remote/builder/build_checks.sh`:

* `add_tls_to_server_conf FILE CERT KEY [LE_DIR]` now also adds `include /etc/letsencrypt/options-ssl-nginx.conf;` and `ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;` to the 443 block, but only when those two files exist on the host (they do on dev), so a host without them still builds.
* `https_redirect_block FILE DOMAIN` turns the port 80 block of the sites name in `<reponame>.nginx.conf` into `listen 80; return 301 https://$host$request_uri;`. It is applied only after the 443 file was accepted by `nginx -t` and `apachectl -t`, as its own install with the previous file kept; a refused redirect is put back with a warning and the name stays reachable on HTTP. The member's own `www.<domain>` block is not touched.

**Checked:** both functions run in bash on the Windows PC (output as expected; a missing block, a bad name and a bad folder fail without changing the file), and the generated 443 block (with the real `/etc/letsencrypt` files) and the redirect block pass `nginx -t` in a throwaway sandbox on the dev server. Two cases were added to `tests/Feature/BuildChecksShellTest.php`; they need `/bin/bash`, so they have **not been run** on this PC. **Not run:** a real tenant build with the new scripts; the scripts are not uploaded to the dev server's members project yet.
