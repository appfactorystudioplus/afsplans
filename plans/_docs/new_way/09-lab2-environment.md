# 09 — lab2: what it needs for website deployment

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/09-lab2-environment.md](../domain_name_management/09-lab2-environment.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The tenant host, the website worker and the website acceptance items are here; the shared hazard and guard (§9.1), the settings (§9.2), the test names, the DNS VM and the domain acceptance items are in the domain-name half.

lab2 (`C:\projects\vagrant\lab2`) is where this is built and proved. It already has the right shape — a DNS VM running
BIND, a Brain VM running the estate, a plain tenant host — and a tenant-deploy tool of its own. It is missing the pieces
this plan adds, and its seed data is unsafe for it.

```
 lab2-dns   10.0.0.242  BIND, zone test2 (+ tenants.zone for sites.test2)                      ← member zones published here
 lab2-brain 10.0.0.243  nginx+Apache+php-fpm, MariaDB, Redis, the apps (www = Brain, members)   ← claims, publisher, deploy runner, queue
 lab2-web   10.0.0.244  nginx :443 → Apache :808 → php-fpm, MariaDB, Node; tenants under /home   ← the HTTP server sites land on
```

> **§9.1 (production servers in the lab database, and the guard)** affects the DNS servers and the web servers alike and is in the domain-name plan: [../domain_name_management/09-lab2-environment.md](../domain_name_management/09-lab2-environment.md). Read it first: a "create website" run against an unguarded lab acts on a production host.

## 9.2 Configuration — website building settings in the Brain settings, not in `.env`

Every domains and website-building setting lives in the Brain settings document (`controller.domains`); there is no `.env` key, no default and no fallback. The full explanation, and the lab's values for the domain settings, are in the domain-name plan: [../domain_name_management/09-lab2-environment.md](../domain_name_management/09-lab2-environment.md). The lab's values for the website-building settings:

```
dns_agent / tenant_agent     ssh_runner /home/appfactorystudio/websites/www/core/scripts/ssh/afs-ssh-run, sudo false   (no keys: the login is on each server's record)
deploy.template_url          https://gitea.10-0-0-243.sslip.io/appfactorystudio/afsportal_v1.git
deploy.template_ref          (empty on purpose: no template pinned, so no website can be built)
deploy.sites_cert / _key     /etc/nginx/certs/tenants.pem / tenants-key.pem   (on the web VM)
deploy.require_node          true
```

> **§9.3 (test names), §9.4 (playing the customer's own DNS provider) and §9.5 (changes to the DNS VM)** are in the domain-name plan. The lab test sites `tenantN.10-0-0-243.sslip.io` are hosted on the `brain` VM (D18a).

## 9.6 Changes to the tenant host (`provision/tenant-host-setup.sh`)

- Install `afs-tenant-agent` and the deploy user + `sudoers` rule; `/var/log/afs-tenant/`.
- **Server initiation (07 §7.2):** a block at the end of `scripts/initial_install.sh` (the same block ends
  `packages/afsserver/initial_install.sh`, which every production server runs): `node`/`npm`/`npx` symlinks into `/usr/local/bin`,
  the npm token into root's `~/.npmrc`, the GitHub token into Composer's global auth, from `AFS_NPM_TOKEN` and `AFS_GITHUB_TOKEN` in
  the shell that runs `vagrant up` (never stored). The `Vagrantfile` passes both variables to the `initial-install` provisioner on
  `brain` and `web`. A later token change means running that block by hand; the script is not safe to re-run whole.
- Directories: `/home/<tenant>/…` is created per tenant by the agent; ensure `/mnt/node1` (already mounted) and a releases
  cache path.
- Default/parking vhost: any `*.sites.test2` or unknown `Host` gets the platform page, not the first tenant's site
  (07 §7.5). This is also the `parking_ipv4` target.
- Keep as is: nginx :443 → Apache :808 → php-fpm, the `tenants` wildcard cert (`*.sites.test2`), MariaDB with an
  unauthenticated root over the local socket, Node.
- The Brain→web SSH trust that `Setup-BrainTrust.ps1` already establishes is what the agent transport uses.

## 9.7 Changes to the Brain VM

- A supervised worker for the `deploy` and `dns` queues (a service definition in `provision/`, started like the other services)
  and the scheduler entry for the verifier, publish-reconcile, reservation sweep and purchase reconciler. `routes/console.php`
  already carries scheduled work, so this adds entries rather than a new mechanism.
- Tenant dependency installs run on the tenant host itself (07 §7.3), so the Brain VM needs no release builder; it needs the same
  server initiation as any tenant host when it doubles as one (D18).
- No change to the apps' web serving.

> **§9.8 (the registrar in the lab, the `fake` driver)** is in the domain-name plan.

## 9.9 Acceptance in lab2 (P0 exit)

`tenant1`–`tenant3` already exist (§9.11) and serve as fixtures for items 5, 8, 9 and 10. Items 2, 3 and 7 use fresh names
(`tenant4.10-0-0-243.sslip.io`, `tenant5…`).

5\. A presses *Create website* on `tenantN`: the server is chosen (explanation visible), the pipeline runs to 100 %, and **both**
   `https://tenantN.10-0-0-243.sslip.io` and `https://<ref>.sites.test2` (and `http://www.tenantN…`) return the same site, with no certbot call
   (`LETSENCRYPT_CHECK=false`).
6\. Kill the worker mid-deploy; it resumes (Path B). Cancel mid-deploy; nothing is left on the host, in DNS or in the database.
8\. Delete the website; the names stop answering; the claim and the zone remain.
10\. Move the site from `brain` to a second host (step 12 §12.13): the move completes, both names serve from the target, the source shows the
    forwarder, the registry and DNS agree, and a rollback returns it.

(Items 1–4, 7 and 9 — the seeder and guard, connect, collision, DNS edit, purchase, release — are in the domain-name plan.)

## 9.11 What exists in the lab today (built 2026-10-07; recorded in `complete/00_extras.md` E1–E3)

Interim tooling, written before the pipeline so the process could be run by hand. It is the working reference for what the DNS publisher
and the tenant steps must reproduce; the pipeline replaces it, it does not need to be kept in step with it.

| What | Where | Does |
|---|---|---|
| `lab2/brain/add-site.sh` | runs on the brain VM | Linux user `u<ref>`, DB `u<ref>_db` with its own generated password, one docroot, and the **production tenant layout**: `/mnt/node1/u<ref>/{mailbox,modules,services,temp,tmp,server_configurations/{nginx,httpd,letsencrypt}}` (ownerships as `make_storage_directories`) with **one `u<ref>-www.conf` per web server** under `server_configurations/` and symlinked into `conf.d`; a VirtualHost / server block per name (apex HTTPS with the sslip wildcard, sites name HTTPS with `tenants.pem`, `www` HTTP-only), placeholder `index.php` + `health.php` |
| `lab2/brain/register-lab-domain.php` | runs on the brain VM | `brain_dns_zones`, `brain_dns_subdomains`, `brain_http_server_tenants`, `members_websites` rows under member `james1` (`m1xTHpRmdtH2Y`) |
| `lab2/provision/production-paths.sh` | brain, web | `/mnt/node1/appfactorystudio/{logs,tmp,temp,backups,programs/tenant_transfers}` and `/mnt/node2/appfactorystudio/tmp`, the tree the production scripts write to; chowns only what it creates |
| `lab2/provision/vhost-lib.sh`, `consolidate-vhosts.sh` | brain | **One nginx file and one Apache file for the platform**, as on production: `/etc/nginx/conf.d/appfactory.studio.zone.conf` and `/etc/httpd/conf.d/appfactory.studio-vh.conf`, one `# ---- app-<name> ----` block per app. `deploy-app.sh`, `deploy-node-app.sh`, `deploy-livekit.sh`, `deploy-parse.sh` now write into them; `00-connection-upgrade.conf`, `php-fpm.conf`, `ssl.conf` stay separate, as on production (done 2026-10-08, blocks verified identical to the old files) |
| `lab2/web/afs-tenant-agent`, `lab2/web/install-tenant-agent.sh` | web VM | The Brain's tenant agent (07 §7.2; canonical source `w/www/core/scripts/tenant/afs-tenant-agent`) and its installer (`/usr/local/bin`, `/var/log/afs-tenant`, the offline page); `provision/tenant-host-setup.sh` runs it. **Installed on the running web VM 2026-10-09** and exercised by `DeployAgentLabTest` and `LabDeployTest` (opt-in, `AFS_LAB_WEB=1`). The VM has `jq`, `php`, `composer`, `git`; it has **no `node`/`npm`** (07 §7.2 server initiation), so a real template's `preflight` fails there with that reason until it is installed. `deploy-tenant.sh` still exists and writes `/etc/httpd/conf.d/tenant-<name>.conf` — the agent uses the same file name per tenant, so do not run both for one name |
| Credentials | `brain`, `web` | `/root/.npmrc` (npm token) and Composer's GitHub token for root, applied 2026-10-07/08; the block that does it is at the end of `initial_install.sh` (both copies) |

State after the run: the three zones answer authoritatively on `10.0.0.242`; the three sites answer on the apex (HTTPS, verified) and on
`<ref>.sites.test2`; `www` answers over HTTP; the existing apps are unaffected. The hazard in §9.1 is fixed in the **running** database (server rows point at lab hosts) but **not in the seed file** a fresh lab loads.

## 9.10 Phases

| Phase | Work |
|---|---|
| **P0** | §9.6 tenant host; §9.7 worker; acceptance §9.9 items 5–6 |
| **P1** | Item 8; `deploy-tenant.sh` retired; optional second tenant host for selection tests |
| **P2** | Per-lab-domain mkcert certificates for custom names |
