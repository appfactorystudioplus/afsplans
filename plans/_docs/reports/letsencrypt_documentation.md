# Let's Encrypt wildcard certificates — how they are issued and managed

Written 2026-10-24. Everything here was run on the dev server (`root@45.79.42.137`, which is the Brain, the DNS server and the tenant web host at once). Code: `w/www/app/Services/Brain/SitesCert/` and `app/Console/Commands/Brain/SitesCert*.php`. Plan: `plans/domains_deploy/old_way/05-certificates.md`; changes: `plans/domains_deploy/domain_name_management/complete/00_extras.md` E20, E21.

## 1. What it does

The Brain keeps wildcard certificates, one per base domain, issued by Let's Encrypt with a DNS-01 challenge (a wildcard cannot be proved over HTTP):

| Wildcard | Used for | Put on the web hosts at |
|---|---|---|
| `*.sites.appfactory.studio` | tenant website names (`<ref>.sites.appfactory.studio`) | `/etc/nginx/certs/tenants.pem` and `tenants-key.pem` |
| `*.storage-device.<STORAGE_BASE_DOMAIN>` (dev: `members15.appfactory.studio`) | storage device hostnames (`<N>.storage-device…`) | `/etc/letsencrypt/live/<domain>/fullchain.pem` and `privkey.pem` |

There is **one** Let's Encrypt: no staging, no development or production certificate. Anything that runs is real.

## 2. How a run works

1. Look at the certificate the Brain holds. Do nothing unless it is missing, has 30 days or fewer left, or the run is forced (`--force`).
2. Remove any challenge line a crashed earlier run left on the DNS servers.
3. `certbot certonly --manual --preferred-challenges dns`. Its hooks run `php artisan sites-cert:challenge add|remove --domain=<domain>`:
   - `add` writes one line into the DNS include file, `_acme-challenge.<domain>. 60 IN TXT "<value>" ; afs-acme`, runs `systemctl reload named`, and waits (up to 120 s) until `dig @127.0.0.1` answers with the value;
   - `remove` deletes every line ending in `; afs-acme`.
4. Send the pair to every active web host (`brain_http_servers`) over ssh. On the host: check the certificate and that the key belongs to it, write it beside the target and move it in, run `nginx -t`, then `systemctl reload nginx`. If `nginx -t` fails the previous pair is put back (`.prev`) and the host is reported as not having it.
5. Record the result in `state.json` and raise an alarm in the log if 14 days or fewer are left and it is not fixed.

A pair that was issued but did not reach every host is sent again on the next run **without** asking Let's Encrypt again.

## 3. Settings

Only these two, in the Brain settings editor under `letsencrypt_cert_management`: `tenant_cert` and `tenant_cert_key` (`/etc/nginx/certs/tenants.pem`, `/etc/nginx/certs/tenants-key.pem`). Everything else is built in (`SitesCertSettings::value()`): the zone `sites.appfactory.studio`, the account e-mail, `certbot`, `nginx -t`, `systemctl reload nginx|named`, the include file `/var/named/zones/appfactory.studio.include.zone`, renew at 30 days, alarm at 14, wait 120 s, and the work folder `storage/app/sites-cert`.

The servers' own logins are on their rows: the DNS server in `brain_dns_servers`, each web host in `brain_http_servers` (`json.users[]`). The Brain logs in with the bash and expect runner `core/scripts/ssh/afs-ssh-run`.

## 4. Commands

Run every command as the user that owns `storage/app` (on dev `apache`), never as root, or the work folder becomes root-owned and the scheduled run fails. Without `--domain`, a command works on every wildcard the platform keeps: the sites zone, plus `storage-device.<STORAGE_BASE_DOMAIN>` when that `.env` variable is set (`SitesCertDomains::all()`). With `--domain=<base domain>` it works on that one, `*.<base domain>`; any valid domain name works.

**Check that the commands are on the server.** Four commands should be listed: `sites-cert:renew`, `:distribute`, `:status`, `:challenge`.

```bash
cd /home/appfactorystudio/websites/dev/www && php artisan list | grep sites-cert
```

**Dry run, every wildcard.** Says what it would do (which wildcards are due, which DNS servers would publish the challenge, which hosts would receive the pair, and where). Contacts nobody. Run it before every first real run.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --dry-run
```

**Dry run, one wildcard.** The same, for one domain.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --dry-run --domain=storage-device.members15.appfactory.studio
```

**Issue or renew, every wildcard.** For each wildcard: nothing if it is not due and every host has it; otherwise ask Let's Encrypt, then send to the hosts. This is what the daily 04:10 run does.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew
```

**Issue or renew, one wildcard.** The first issue of a new wildcard. It is installed on the web hosts at `/etc/letsencrypt/live/<domain>/fullchain.pem` and `privkey.pem`.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --domain=storage-device.members15.appfactory.studio
```

**Issue the sites wildcard.** The sites zone, named explicitly. It is installed at the `tenant_cert` / `tenant_cert_key` paths.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --domain=sites.appfactory.studio
```

**Force a renewal now.** Ask Let's Encrypt even though the certificate is not due. Counts against its limits (5 duplicates per week), so use it only when the held certificate is wrong.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --force --domain=sites.appfactory.studio
```

**Send the held certificate to the hosts, every wildcard.** Does not contact Let's Encrypt. Use it when issuing worked and only the sending failed (wrong ssh password, `nginx -t` failing).

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:distribute
```

**Send one wildcard, to one host.** `--server` is the web server row's reference (`node1.appfactory.studio` on dev).

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:distribute --domain=sites.appfactory.studio --server=mk7r2dwvaz1
```

**Status, every wildcard.** For each: the zone, the end date and days left, where the pair is put, the last run, and which hosts have it. Exits 1 if a certificate is missing, has 14 days or fewer left, or the last run failed.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:status
```

**Status, one wildcard.** The same, for one domain.

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:status --domain=storage-device.members15.appfactory.studio
```

`sites-cert:challenge add|remove --domain=<domain>` is certbot's hook. certbot runs it itself with the right values; it is not for hand use.

The daily run is `brain:run sites-cert:renew`, scheduled at 04:10 in `members` (`routes/console.php`; the members scheduler must be running). It renews at 30 days left and retries hosts that missed the pair.

## 5. First-time setup on a server

1. Upload the code (`packages/afsserver/bin/upload-changed.sh`) and check the commands are there (section 4, first command).
2. In the Brain settings editor, under `letsencrypt_cert_management`, set `tenant_cert` and `tenant_cert_key`.
3. Check the domain is delegated to your DNS server. Let's Encrypt looks up `_acme-challenge.<domain>` from the internet. It must print `ns1.appfactory.studio.`:

```bash
dig +short NS appfactory.studio @8.8.8.8
```

4. Check the ssh login stored for the DNS server row and for the web server row. With the stored password it must print `ok`:

```bash
ssh root@45.79.42.137 echo ok
```

5. Check nginx's own test passes by itself (see problem 5 in section 6):

```bash
nginx -t
```

6. Check who owns `storage/app`; that is the user for every command in section 4:

```bash
stat -c %U /home/appfactorystudio/websites/dev/www/storage/app
```

7. Check certbot starts for that user (problem 1 in section 6):

```bash
runuser -u apache -- /usr/bin/certbot --version
```

8. Dry run, then the real run, then status (section 4).

Do steps 3 and 8's dry run first: a failed validation counts against Let's Encrypt's limits (about 5 failures per hour per name; 5 duplicate certificates per week).

## 6. Problems we hit, and the fix

| # | What it said | Cause | Fix |
|---|---|---|---|
| 1 | `cannot create snap home dir: mkdir /usr/share/httpd/snap: permission denied. Sorry, home directories outside of /home needs configuration` | `certbot` is a snap. A snap takes the user's home from `/etc/passwd`, not from `$HOME`. The web user `apache` has `/usr/share/httpd`, which is root-owned and outside `/home`. Setting `HOME` for the process changes nothing. | Give `apache` a home under `/home`: `mkdir -p /home/apache-home && chown apache:apache /home/apache-home`, then `usermod -d /home/apache-home apache`. |
| 2 | `usermod: user apache is currently used by process …` | PHP-FPM (and a Node service) run as `apache`. | Stop them (`systemctl stop php-fpm`, and whatever `systemctl status <pid>` names for the Node process), run `usermod`, start them again. Check with `runuser -u apache -- /usr/bin/certbot --version`. Editing `/etc/passwd` by hand also works but is riskier. |
| 3 | `Unable to find manual-auth-hook command '/usr/bin/php' in the PATH` | We passed the hook as `'/usr/bin/php' '/…/artisan' …`. certbot checks the first word with `which` and keeps the quote characters. | The hook is now two plain unquoted absolute paths. (Under php-fpm `PHP_BINARY` is `php-fpm`, so the command-line php is looked up with `PhpExecutableFinder`.) |
| 4 | `FAILED on <ref>: root@<ip>: Permission denied (publickey,…,password)` after "Let's Encrypt issued the certificate" | The certificate was issued. The web server row's stored password was stale; the DNS row's was right (same machine, two rows). | Correct the password on the web server row, then `sites-cert:distribute`. Do not use `--force` (it would ask Let's Encrypt again). |
| 5 | `FAILED on <ref>: the web server refused the new certificate; the previous one is back` | `nginx -t` failed for a reason unrelated to the new pair: a server block in `appfactory.studio.dev.zone.conf` pointed at `/etc/letsencrypt/live/storage-device.members15.appfactory.studio/fullchain.pem`, which had never been issued. nginx had kept running on its old in-memory config, so nothing looked broken. | Issue that wildcard (it is the second certificate in section 1), then `sites-cert:distribute` for the sites one. The script's rollback is correct behaviour. |

### Fix commands for the problems above

**Problem 1 and 2, give `apache` a home under `/home`.** Run as root:

```bash
mkdir -p /home/apache-home && chown apache:apache /home/apache-home
```

See what runs as `apache` and which services own it (PHP-FPM, and a Node process on dev):

```bash
ps -u apache -o pid,ppid,comm,args
```

```bash
systemctl status <pid> --no-pager | head -6
```

Stop those services, change the home, check, start them again:

```bash
systemctl stop php-fpm
```

```bash
usermod -d /home/apache-home apache
```

```bash
getent passwd apache
```

```bash
systemctl start php-fpm
```

The `getent` line must end with `:/home/apache-home:/sbin/nologin`. Then confirm certbot starts:

```bash
runuser -u apache -- /usr/bin/certbot --version
```

**Problem 4, after correcting the web server row's password,** send what is already held (do not use `--force`):

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:distribute
```

**Problem 5, see exactly what nginx is refusing:**

```bash
nginx -t
```

```bash
grep -rn "storage-device.members15" /etc/nginx
```

Then issue the missing wildcard and send the sites one:

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --domain=storage-device.members15.appfactory.studio
```

```bash
cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:distribute --domain=sites.appfactory.studio
```


Earlier design decisions that cost time and were removed: a Let's Encrypt staging mode that followed `VITE_SUBDOMAIN` (a staging certificate cannot serve a browser, so dev could never finish), an `enabled` switch, and a settings group for paths and commands that never change.

## 6a. How a tenant's nginx uses the sites wildcard

A build writes two files on the web host (`app_install.sh`, `build_checks.sh`):

| File | Contents |
|---|---|
| `/etc/nginx/conf.d/<reponame>.nginx.conf` | One port 80 `server` per name, from `http/temp-nginx-vh.conf`: `www.<member domain>` and `<ref>.sites.appfactory.studio`. Each proxies `/` and `/portal/api` to Apache on `localhost:808`. When the wildcard pair is on the host, the sites name's block is replaced by `listen 80; return 301 https://$host$request_uri;`. |
| `/etc/nginx/conf.d/<reponame>.tls.nginx.conf` | Only when `tenant_cert` and `tenant_cert_key` exist on the host: the same block for the sites name with `listen 443 ssl;`, `ssl_certificate`, `ssl_certificate_key` and, if the files exist, `include /etc/letsencrypt/options-ssl-nginx.conf;` and `ssl_dhparam`. |

Every file is installed with the old copy kept as `.prev`, then `apachectl -t` and `nginx -t` must pass, or the old file is put back. Without the pair on the host the sites name is served over plain HTTP and the log says so. The member's own domain gets no certificate yet (plan 05 §5.2).

## 7. Where things are

| What | Where |
|---|---|
| Work folder | `storage/app/sites-cert/` (owned by the web user): `config/`, `work/`, `logs/letsencrypt.log`, `state.json` (`state-<domain>.json` for other domains) |
| certbot lineage | `storage/app/sites-cert/config/live/<name>/` (`sites-wildcard`, or the domain) |
| The certificate on a host | section 1 |
| The challenge line | the DNS include file, tagged `; afs-acme`; present only while a run is waiting |
| Backups of a replaced pair on a host | `<target>.prev` (only until the next successful install) |
| Tests | `w/www/tests/Feature/Brain/SitesCertTest.php` (18 tests; certbot and ssh are faked) |

## 8. Not done / watch for

- The pair goes to the active web hosts only. A separate storage server (`brain_storage_servers`) is not a target yet; on dev it is the same machine.
- Tenants built before the wildcard keep their own per-name certbot certificates; only new builds use the wildcard.
- A secondary name server that copies the zone by transfer will not see the challenge line until the serial moves. Dev has one DNS server, which is also the primary; check production.
- Certificate expiry for a member's own domain (not the platform wildcards) is not built (plan 05 P1).
