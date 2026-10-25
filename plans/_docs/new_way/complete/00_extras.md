# website deployment — off-plan work (`complete/00_extras.md`)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **website deployment** half; the **domain name management** half is [../../domain_name_management/complete/00_extras.md](../../domain_name_management/complete/00_extras.md). Numbers (§, F, D, E) are unchanged.

What was actually done outside a numbered step, dated, for the website-deployment side. Nothing here replaces the pipeline of step 07; it is the interim and Path A work (07 §7.0) and the lab tooling (09 §9.11). Section numbers E1–E11 are unchanged from the combined file.

## E1 — Lab test domains `tenant1`–`tenant3.10-0-0-243.sslip.io` (2026-10-07)

Owner request: because the lab's DNS is private, use names that follow the lab convention and run the whole sequence by hand — zone file,
zone stanza in `/etc/named.conf` on the DNS VM, then the website.

Built (all under `C:\projects\vagrant\lab2`):

| File | Purpose |
|---|---|
| `brain/add-site.sh` | On the brain VM: Linux user, DB with its own password, one docroot, one Apache vhost with every name, nginx vhosts, placeholder site |
| `brain/register-lab-domain.php` | Registry rows (`brain_dns_zones`, `brain_dns_subdomains`, `brain_http_server_tenants`, `members_websites`) under member `james1` |

Result, checked from the PC: the DNS server answers authoritatively (`aa`) for SOA/A/CNAME/TXT and the sites record on all three; each site
answers `200` with `"db":true` on `https://tenantN.10-0-0-243.sslip.io/health.php` (certificate verified) and on `https://<ref>.sites.test2`
(via `--resolve`); `http://www.tenantN…` answers; `http://tenantN…` redirects to HTTPS; other lab apps unaffected. Backups on the DNS VM:
`/etc/named.conf.pre-lab-domains`, `test2.zone.bak-*`, `tenants.zone.bak-*`.

(The zone files, the zone stanzas, `dns/add-zone.sh`, `provision/dns.sh` and the production-rows-in-the-seed note are in the domain-name report.)

## E2 — Gitea settings, `app_install.sh` remote, `LETSENCRYPT_CHECK` (2026-10-07)

- Brain `brain_config` (lab) `controller.gitea_3051`: `url` `https://gitea.10-0-0-243.sslip.io/appfactorystudio` → `https://gitea.10-0-0-243.sslip.io`;
  `domain` `gitea.10-0-0-243.sslip.io/appfactorystudio/afsportal_v1` → `gitea.10-0-0-243.sslip.io`. Original row saved in
  `brain_config_backup_20261007`. Both accounts' tokens verified valid against the lab Gitea; `appfactorystudio/afsportal_v1` is reachable with a
  `main` branch.
- `app_install.sh` (`w/www` and `w/members` copies), `run_create_repo`: `git remote add origin` now uses `${gitea_base_url}/${GITEA_USERNAME2:-appfactorystudio2}/${reponame}.git`
  (the base URL is saved before `gitea_url` is turned into the API URL).
- `LETSENCRYPT_CHECK`: read from the job config (`letsencrypt_check`, default `true`); certbot is skipped unless `true`. Added to the job config in
  `members/app/Http/Controllers/Brain/DomainController.php` (`createWebsite`), `www/app/Http/Controllers/Brain/BrainHttpServerController.php`
  (`moveTenants`) and `www/app/Services/Brain/Infra/InfraTransfer.php`; read via `members/config/services.php` and `www/config/brain.php`;
  `_env/<env>/.env` of members and www: `false` for `lab2`, `vagrant`, `vagrant-lan`, `local`, `true` for `dev`, `prod`. `use-env.sh lab2` was run
  (backups in `w/.env-backups/20261007-220741-lab2`).
- Node on the non-interactive PATH at the top of `app_install.sh` (the `members` copy by the owner, the `www` copy by a glob of the newest nvm Node).

## E3 — Tenant-host dependency access (2026-10-07 / 2026-10-08)

- Block "Tenant host dependency access" at the end of `packages/afsserver/initial_install.sh` and `lab2/scripts/initial_install.sh`: Node/npm/npx symlinks into
  `/usr/local/bin`, the npm token into root's `~/.npmrc`, the GitHub token into Composer's global auth, from `AFS_NPM_TOKEN` and
  `AFS_GITHUB_TOKEN` (environment only). `lab2/Vagrantfile`: the `initial-install` provisioner on `brain` and `web` passes both variables. A
  standalone `provision/tenant-deps.sh` was written and then removed in favour of the block.
- Applied by hand to `brain` (10.0.0.243) and `web` (10.0.0.244): `/root/.npmrc` (npm token, mode 600; `npm whoami` and `npm view
  @appfactory/checkpoint-client version` succeed) and Composer's GitHub token for root (`afs/brain-client` and `afs/checkpoint-auth` resolve).
  The nvm Node 22.13.0 resolves for root over non-interactive SSH on both.
- Undecided and recorded in `plans/TODOS.md`: `npm ci` versus `npm install` for tenant installs.

## E4 — Template copy script (2026-10-07)

`packages/afsserver/bin/copy_portal_source.sh` now defaults to `w/www` → `packages/wwwafsportal_v1` (paths resolved from the script's location) and falls
back to `rm` + `cp -a` when `rsync` is missing. Run once; every listed item copied.

## E5 — Progress step numbers: tried and reverted (2026-10-08)

Distinct step numbers (1–19) and a "highest step reached" calculation in `ProgressService` were written, then **reverted** at the owner's direction: the
model is additive by design (every call reports `1`, the total reaches `max_steps`, `complete` forces 100%). Net change to those files: none.
The audit (01 F29) and 07 §7.7 now describe the additive model.

## E6 — Production-style paths and consolidated vhost files in the lab (2026-10-08)

Owner request: everything should mimic production, including the paths, and the platform's nginx and Apache files should be single files as on production.

- **brain and web:** `provision/production-paths.sh` creates `/mnt/node1/appfactorystudio/{logs,tmp,temp,backups,programs/tenant_transfers}` and
  `/mnt/node2/appfactorystudio/tmp` (owner `appfactorystudio:apache`, 775). Wired into the `Vagrantfile` after `initial-install`.
- **brain:** `provision/consolidate-vhosts.sh` merged the 14 nginx and 9 Apache `app-*.conf` files into
  `/etc/nginx/conf.d/appfactory.studio.zone.conf` and `/etc/httpd/conf.d/appfactory.studio-vh.conf` (one `# ---- app-<name> ----` block each; originals in
  `/root/vhost-backup-20261008-190841`; blocks verified byte-identical to the old files; both configs tested before reload). `provision/vhost-lib.sh`
  (`vhost_put`, `vhost_remove`) is used by `deploy-app.sh`, `deploy-node-app.sh`, `deploy-livekit.sh` and `deploy-parse.sh`, so a fresh build produces the same files.
- **Tenants:** `brain/add-site.sh` now builds the production tenant layout (`/mnt/node1/u<ref>/{mailbox,modules,services,temp,tmp,server_configurations/…}`) with one
  `u<ref>-www.conf` per web server under `server_configurations/`, symlinked into `conf.d`. The three existing tenants were re-created this way (old
  `tenant-<ref>.conf` files saved in `/root/vhost-backup-tenants`). All three sites and all platform apps answered afterwards.
- **Incident (fixed):** the first run of `production-paths.sh` did `chown -R`/`chmod -R` over `/mnt/node2/appfactorystudio`, which also contains Gitea's data. It
  changed ownership of Gitea's data and MySQL volume to `appfactorystudio:apache` (mode 775). Restored the same day: data back to `977:977` (the container's `git`
  user), MySQL volume `999:999` (dirs 750, files 640), SSH host keys 600; `git` can write `/data`, MySQL answers, Gitea's API returns 200. Gitea logged two HTTP 500s
  (session `chtimes`) in the minutes it was affected. The script now creates and chowns only the directories it owns.
- **Not mimicked:** production sites-name zone `appfactory.studio` / `sites.appfactory.studio` (the lab keeps `test2` / `sites.test2`), and the `www` + `www-own` pair of
  copies per tenant (the plan uses one docroot).
- **Verified after a restart (2026-10-08 19:13):** `gitea_db_3051` then `gitea_3051` were restarted. MySQL was alive after 4 s and Gitea answered 200 after 6 s; the API, both tokens,
  `appfactorystudio/afsportal_v1` (`main` at `019e2a56ab`), `git ls-remote` and a shallow clone over HTTPS all work; no error lines in either container's log since the restart.
  The six SSH host-key files are `root:root` 600 again (the image's startup sets that; `sshd` is running), everything else in `data` is `977:977`.

(The DNS VM bullet — the production zone layout — is in the domain-name report.)

> **E7 (all settings in the Brain settings, no `.env`), E8 (ssh by password, bash + expect), E9 (who is on the new flow), E10 (`NameComDriver` to CORE v1) and E11 (the browser builds the invoice)** are in the domain-name report, because the settings group, the ssh runner and the per-member switch are shared infrastructure that the domain work built first: [../../domain_name_management/complete/00_extras.md](../../domain_name_management/complete/00_extras.md). The website-building settings (`deploy.*`, `tenant_agent.*`, `storage_agent.*`) are keys of the same `controller.domains` group.
