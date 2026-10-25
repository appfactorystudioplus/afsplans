# Handoff

> **A rolling continuation file, not a stable reference.** It says what was done, how to work in this estate, what is verified and what is not, and what to pick up next.
> It is expected to be overwritten as work moves on. Do not cite it by section number from a numbered plan; a fact worth keeping belongs in its plan document.
>
> Written 2026-10-24. Covers the domain-name half and the start of the website-deploy half of tenant website deployment.

## 1. Where things stand

Deploying a tenant website has two halves.

1. **Domain names (finished, on the dev server).** Claims decide who owns a name. There is one flow and no domain settings. The Let's Encrypt wildcard, registrar-per-domain and the member DNS record editor are built.
2. **Deploying the site (in progress).** The owner chose the **old way**: members `DomainController::createWebsite` → `core/scripts/background_runner.php --provision_new_website1` → `commands.sh` / `commands.expect` → `core/scripts/remote/builder/app_install.sh`. The member's "Create Website" button is now wired to it (section 3). **No real build has been run yet.**

Everything below is on the dev server `root@45.79.42.137`. That one machine is the Brain, the DNS server and the tenant web host at once. Dev = `VITE_SUBDOMAIN=www15`; `www` or missing = production.

## 2. Commits (2026-10-24, all uploaded to dev, nothing pushed)

| Repo | Commit | What |
|---|---|---|
| `w/www` | `e13e795c9` | wildcard certs for any base domain (`--domain=`), registrar recorded per domain (`RegistrarDrivers`), member DNS record editor built with Badcow (`ZoneRecordRdata`, renderer, `ZoneRecords::spec()`, `expected_serial`) |
| `w/members` | `52c81583` | website screen builds through the old pipeline, DNS record editor screen, nginx TLS include + HTTP to HTTPS redirect in `app_install.sh` / `build_checks.sh` |
| `packages/afsserver` | `3eba6002` | plans: `domain_name_management` steps 14 to 16, the Let's Encrypt report, `old_way/complete/00_extras.md` (E1) |

Left uncommitted on purpose (not mine): `w/www/core/config/testdb_appfactorystudio.data-only.sql`, `packages/afsserver/initial_install.sh`, `packages/afsserver/tmp/__temp_ngin_zone.conf`.

27 files were uploaded with `packages/afsserver/bin/upload-changed.sh --vscode -y -f <list>` (backups in `w/.upload-backups/20261024-175907`). **On the server still to do:** `npm run build` in `/home/appfactorystudio/websites/dev/members` (the screens are TypeScript source); `php artisan route:clear` there only if routes were cached.

## 3. What was built in this stretch

* **Claims are the only domain path.** `DomainClaimService`, `brain_dns_zones`; one active claim per name (generated column `active_fqdn`). Staff actions under `domains-staff/*` plus a Domains page in the Brain admin.
* **No domain settings.** `DomainDefaults::VALUES` holds everything (zone `sites.appfactory.studio`, `platform_ns`, SOA timers, ssh paths, limits). `DomainSettings::get()` reads a `config('domains.*')` override (tests), then the defaults, else throws. The only Brain setting kept for certificates is `controller.letsencrypt_cert_management.tenant_cert` / `tenant_cert_key`.
* **Wildcard certificates** (`app/Services/Brain/SitesCert/*`, `SitesCert*` commands): certbot (snap) with manual DNS-01 hooks `sites-cert:challenge add|remove --domain=`; tagged TXT lines (`; afs-acme`) in `/var/named/zones/appfactory.studio.include.zone`; distribution over ssh via `afs-ssh-run` with `nginx -t` rollback; `--domain=` takes any base domain (non-sites domains install to `/etc/letsencrypt/live/<domain>/{fullchain,privkey}.pem`); daily `sites-cert:renew` at 04:10 from members. **No staging, no environment split.** Full command list: `plans/_docs/reports/letsencrypt_documentation.md`.
* **Registrar per domain.** `controller.domain_registrar` picks the driver for new purchases; `RegistrarDrivers::for(?string)` finds the driver recorded on the purchase or claim, so switching later does not break old domains.
* **Member DNS records** (step 16): the member edits the database (`json_zone_data`); the publisher renders the zone with Badcow and pushes it through `afs-dns-agent`. Types: A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, TLSA, SSHFP, URI. A stale save gets 409 `DOM_E_ZONE_CHANGED`. The members screen draws its form from the server's `types` and `ttl`.
* **Tenant nginx** (`app_install.sh`): `/etc/nginx/conf.d/<reponame>.nginx.conf` has a www server and a sites-name server, both proxying to Apache `localhost:808`; optional `<reponame>.tls.nginx.conf` adds `listen 443 ssl` with the wildcard and the certbot TLS includes; the sites name's port 80 becomes a 301 redirect when the pair is present. Files are installed with `.prev` and restored if `apachectl -t` / `nginx -t` refuse.
* **Website screen rewired (old_way E1).** `surfaceViews.ts` `websiteView` reads the member's `members_websites` rows (`GET /api/v1/domains/websites`): `json.status` `active` shows the manage section, `pending` re-attaches the progress widget to the stored `tracker_key`, otherwise Create. Create posts `{domain_name}` to `POST /api/v1/domains/create-website`, then follows `/api/v1/progress/{key}`. `ProgressController::settleWebsite` flips the row to `active` or `failed`. The Brain-deployer calls (`startWebsite`, `websiteStatus`, `websiteAction`, `deleteWebsite`), their four routes, and the `members_websites` mirror in `DomainSurfaceController` were removed. Enable, disable and Delete Site are **not offered** (no script does them).
* The Brain's new deployer (`app/Services/Brain/Deploy/*`, `DeploymentService`, `DeployRunner`) is still in `w/www` but nothing calls it; its template settings are empty. Plan folder `domains_deploy/new_way` keeps its design.

## 4. Verified and not verified

Verified (local MariaDB, port 3307): `w/www` `tests/Feature/Brain/Domains` 287 tests, 0 failures (21 skipped, need VMs). `w/members` `DomainSurfaceControllerTest` + `DomainRoutesTest` 11 OK. `vitest` domains 8 passed. `tsc` in `w/www` 0 errors; in `w/members` 62 errors, all pre-existing, none in `controllers/domains/`. The nginx TLS/redirect scripts were checked with `nginx -t` in a throwaway sandbox on the dev server.

**Not verified:**
* No real "Create Website" has been run since the changes. It changes real DNS and issues a real certificate (`LETSENCRYPT_CHECK=true`). Old plan acceptance checks A2 to A13 not run.
* The new website screen and the DNS editor have not been seen in a browser.
* A member DNS edit has not been published to the real DNS server from the screen.
* The sites wildcard was issued (ends 2027-01-22), but whether `sites-cert:distribute` has since succeeded is unknown. The storage wildcard has **not** been issued, and until it is `nginx -t` refuses new configs (`appfactory.studio.dev.zone.conf` references `*.storage-device.members15.appfactory.studio`).
* `build_checks.sh` tests in `BuildChecksShellTest` need `/bin/bash`; they do not run on the Windows PC.

## 5. What to pick up next (in order)

1. **Owner, on the server:** `npm run build` in the dev members project; then issue the storage wildcard and distribute the sites one:
   `cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:renew --domain=storage-device.members15.appfactory.studio`
   `cd /home/appfactorystudio/websites/dev/www && runuser -u apache -- php artisan sites-cert:distribute --domain=sites.appfactory.studio`
2. **Owner:** refresh the template repo `afsportal_v1` in Gitea (the agent never touches `wwwafsportal_v1`).
3. **First real build on dev**, through the screen, with the progress log open. Watch `background_runner.php` output and `app_install.sh` progress calls. Fix what breaks; record it in `old_way/complete/00_extras.md`.
4. **Tenant removal script** (old plan A13): a failed build leaves its user, database, Gitea repo and directories behind; there is no script to remove a tenant. Needed before Delete Site / enable / disable can return.
5. Open offers, not decided: delete dead lab files (`Lab*Test`, `09-lab2-environment.md`, `_env/lab2/.env`) and the old browser DNS code (`dns.ts`, `website.ts` old handler, `api.ts` `createWebsiteApi`).
6. The old plan said a GitHub token sits in a comment in `brain_controller_schema.ts`; a search of `resources/js` found none. Re-check and revoke if it exists anywhere.

## 6. How to work here (the owner's standing rules)

* **Commit only when told, one commit per repo.** `w/` is not a git repo; it holds ten independent repos. Say which repo a change landed in. Never push. End commits with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
* **Only test what you modified; never run full suites.** One heavy process at a time, below normal priority, kill leftover node/Electron. Never start the Laravel Vite dev server. Never `config:cache` (the estate does not use it).
* **Don't change the live server without agreement.** Read-only checks are fine. Upload only when asked, with `packages/afsserver/bin/upload-changed.sh --vscode -y -f <list>` (large uploads: run in the background, never under a 110 s timeout; it was cut off once). The front page `index3.blade.php` is the one file uploaded after every edit without asking.
* **No pointless settings, switches, guards or "old ways" for an unpublished app.** Fixed facts are baked into code. Dev vs production only from `VITE_SUBDOMAIN`. No Vagrant-lab references; the work is on the dev server.
* **Never log in as the agent** and never type credentials into a login form. No proactive tool or package installs. `packages/wwwafsportal_v1` is out of scope: never read or edit it.
* **TypeScript: no optional chaining, no `any`.** PHP: no `app()` service location. Every command given to the owner is its own full copy-paste bash block. Plain language; name PHP vs TypeScript and link the file; answer the question first. "Show me" means paste the real code with `path:line`.
* **Plans:** a step is a file, a phase is the work inside it (P0/P1/P2). One completion doc per plan file, named after it, in `complete/`; off-plan work goes in `complete/00_extras.md` as dated `E*` sections.
* **Edit hygiene:** write regex/escape-heavy code with Write/Edit or a Python file, not Bash heredocs (they eat backslashes). Never `git checkout --` a file that holds other uncommitted work (it wiped work twice). Anchor `locales.json` edits and diff against a baseline.
* **Tests:** MariaDB 11.4.5 at `C:\Users\mrmit\mariadb` (port 3307); scripts `$TEMP/rt5.sh` (start, run, shut down), `$TEMP/tsc_mem.sh` / `tsc_www.sh` (log in `$TEMP/tsc_*.log`). Members phpunit needs the DB env override. Background log with progress output; wipe `testdb_phpunit` between runs.
* **Server work:** use `afs-website` / `RunCommandBG` / `commands.sh` / `SshRunner`; ssh by password via bash + expect; scripts to the lab go on stdin. Lab security is never a concern. lab2 is reachable: test, don't assume.

## 7. Facts learned the hard way

* certbot is a snap on the dev server: it reads the web user's home from `/etc/passwd`, which had to be under `/home` (`apache` → `/home/apache-home`, set with services stopped). The certbot hook must use unquoted paths and the CLI php (`PhpExecutableFinder`).
* `fetchBuildData` asks the Brain for signed `build/data`; the Brain keys `subdomain_properties` by the environment label (`www15`, `www`).
* The job file for a build is written 0600 under `/mnt/node1/<systemuser>/tmp/main_<random>/vm_<random>.json` and holds every login the build uses.
* One website per domain at a time: `createWebsite` answers 409 for a live site or a pending one younger than 90 minutes.
* `members_websites.json.status` is `pending` → `active` or `failed` (and `active = 0` on failure), set by `ProgressController::settleWebsite` from the build's progress calls.
* Plan documents: `plans/domains_deploy/{old_way,new_way,domain_name_management}`; step 13 to 16 and their `complete/` records describe the domain half; `old_way/complete/02` to `07` and `00_extras.md` describe the deploy pipeline and what was or was not verified.
