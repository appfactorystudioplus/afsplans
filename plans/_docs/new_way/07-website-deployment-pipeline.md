# 07 — Website deployment pipeline

Deploying a site on an active claim, on the server chosen in step 06, so it answers on **both** the member's domain and
`<site_ref>.sites.<zone>`. This replaces the `popen` → `afs-website` → `commands.sh` → `expect` → `app_install.sh` chain
(F20–F29) with a resumable pipeline run by the Brain.

## 7.0 Two ways to deliver this step

The sections below describe the **target design** (an idempotent tenant agent driven by a queued runner). The owner is currently
working on the **existing shell pipeline** (`members/core/scripts`: `commands.sh`, `commands.expect`, `remote/builder/app_install.sh`,
`background_runner.php`), and that is the faster route to a working lab. So there are two paths, and nothing else in this plan
depends on which one delivers the deploy (steps 02–06 and 08 are the same either way):

| | **Path A — fix the shell pipeline in place (P0)** | **Path B — agent and runner (P1+)** |
|---|---|---|
| Orchestration | `DomainController::createWebsite()` → `background_runner.php` → `commands.sh` → `commands.expect` → `app_install.sh` | `DeployWebsiteJob` on the Brain with stored steps (§7.7, §7.8) |
| Progress | `record_progress` posts additive `step = 1` calls (§7.7, F29) | Runner records `step = 1` per completed step |
| Resume / cancel | Not available; re-run | Yes |
| Secrets in the job file | Still the whole payload (F27) until trimmed | Least payload per action |
| Where it is done | Edits to the existing scripts and PHP | New code, scripts retired afterwards |

**Path A work list** (each item is a defect from step 01 fixed where it lives; status as of 2026-10-08):

| Done? | Item | Where | Audit |
|---|---|---|---|
| ✔ | Gitea `remote add origin` takes its host from `git_settings.gitea_url`, and the owner account from `gitea_two` | `app_install.sh` `run_create_repo` (both copies) | — |
| ✔ | Brain config `gitea_3051.url` / `.domain` are the bare host (they carried `/appfactorystudio…`) | Brain `brain_config` (lab) | — |
| ✔ | `LETSENCRYPT_CHECK` read from the job config; certbot skipped when not `true` | `app_install.sh` (`run_create_repo`; `restart_http` in the `www` copy), `DomainController`, `moveTenants`, `InfraTransfer`, `config/services.php`, `config/brain.php`, `_env/*/.env` | — |
| ✔ | Node/npm resolvable by the non-interactive root session; npm and GitHub tokens for root | `app_install.sh` header; `initial_install.sh` block (§7.2 "Server initiation") | — |
| ☐ | A unique, generated DB password per tenant, used consistently (stop the two literals) | `DomainController::getTenantData`, `app_install.sh` | F22 |
| ☐ | The sites-name record written idempotently (no `echo >>`), with the parent serial bumped | `app_install.sh build_dns` → the `add-zone.sh` logic (09 §9.11) | F8 |
| ☐ | The member zone published to BIND on activation, with a zone stanza in `/etc/named.conf` (04 §4.4) | new call from the claim flow, using the same logic | F6, F9 |
| ☐ | One docroot / one vhost for both names (or keep `www`/`www-own` until the template is checked against the new layout, T1) | `app_install.sh` | F23 |
| ☐ | The vhost host name consistent with `Address` (`www.<domain>`, not `www15.<domain>`) | `getTenantData`, `app_install.sh` | F24 |
| ☐ | Trim the job JSON to what each step needs | `DomainController::createWebsite()` | F27 |
| ☐ | Server selection replaced by the selector (06) | `DomainController::selectTenantServer` | F17 |

Path B starts after Path A is green on the lab; it replaces the same functions one at a time, which is why the work list above uses
the same names as §7.11.

## 7.1 Accepting a deploy

`POST /api/v1/domains/{claim}/website` (members) → `POST /domains/{claim}/websites {client_key}` (Brain, signed). The Brain
answers **202** with `{deployment_ref, tracker_key}`; everything after is asynchronous and polled through the existing
progress widget.

Preconditions, all checked in one transaction (a failure is a 4xx with a reason code, never a half-started deploy):

1. The claim is `active` (not `suspended`, `contested`, `lapsed`).
2. No live tenant for the claim (`active_claim` unique index, 03 §3.7) — a second request returns the first
   deployment (idempotent) or 409.
3. Entitlement: feature 1's `canUse` has been honoured by the UI; the Brain also applies the server-side count when
   D11 is on. `consume` is recorded at acceptance, `release` on failure (feature 1 F1.5).
4. A template ref is pinned as `current` (§7.3).
5. A server qualifies (06). The reservation is taken in the same transaction (06 §6.4).
6. `client_key` makes a repeated click the same deploy.

## 7.2 The tenant agent (replaces the zip-and-expect builder)

Each HTTP server carries `afs-tenant-agent`: a small versioned shell program installed by the server's provisioning (for
lab2, `provision/tenant-host-setup.sh`), invoked by the Brain over SSH. It is the successor of `app_install.sh` and of
lab2's `brain/deploy-tenant.sh`, which are the same idea written twice.

- **Contract:** `afs-tenant-agent <action>`; JSON request on stdin, JSON result on stdout, non-zero exit on failure, a log
  per tenant at `/var/log/afs-tenant/<ref>.log`. **Every action is idempotent** — running it twice leaves the same state.
- **Actions:** `ping` · `version` · `preflight` · `create-tenant` · `fetch-source` · `install-release` (checkout, `composer install`,
  `npm ci`, build) · `write-env` · `migrate-seed` · `write-vhost` · `health` · `disable-site` · `enable-site` · `switch-release` ·
  `remove-tenant` · `setup-mail` (carries the behaviour of today's `write_dovecot_files`; P1).
- **Least payload:** each call carries only what that action needs (F27); `fetch-source` gets a **read-only** Gitea token for that one
  call (passed as a one-shot HTTP header, never written into the clone's config). The agent **generates** the tenant's database
  password itself, writes it to the tenant's `.env` and (as the template expects) to its own `subdomain_properties`;
  the Brain never holds or transmits it. No Brain-wide credentials, Gitea tokens or root passwords travel to a tenant
  host.
- **Transport:** SSH key from the Brain to a deploy user whose `sudoers` entry allows only the agent (lab2: root key
  trust already exists via `Setup-BrainTrust.ps1`). A password-based transport (what production uses with `expect`
  today) is a fallback inside **one** transport class, never on a command line or in a log (P1).
- **Versioning:** `ping` returns the agent version; the selector's `agent_version` filter (06 §6.2) keeps old agents out
  of new releases. The agent is distributed like any package; a mismatched agent fails step 3, not step 9.

### Server initiation (done once per HTTP server, before it can take tenants)

Tenants install their own dependencies on the host the way every Laravel project does, so the **host** must be able to. That is a
one-time part of bringing a server into service, not of any deploy:

| Prerequisite | Why | lab2 | Production |
|---|---|---|---|
| `node`, `npm`, `npx` on root's non-login PATH (symlinks in `/usr/local/bin` to the nvm install) | The agent runs over non-interactive SSH, which does not load nvm; on `web` today `node` is not on PATH at all | the "Tenant host dependency access" block at the end of `initial_install.sh` | server runbook |
| npm token for the private `@appfactory` registry in root's `~/.npmrc` | `@appfactory/checkpoint-client` is private (the project `.npmrc` names the registry but holds no credential) | same block, from `AFS_NPM_TOKEN` | server runbook |
| GitHub read token in Composer's global auth | Only for templates/apps that require `afs/checkpoint-auth` or `afs/brain-client` (private GitHub VCS repos). `www` does not | same block, from `AFS_GITHUB_TOKEN` | server runbook |
| `composer`, `git`, `certbot`, PHP, MariaDB, nginx, Apache :808 | Install, migrate, serve | `initial_install.sh`, `tenant-host-setup.sh` | existing images |
| The agent and the wildcard certificate | 07 §7.2, §7.6 | `tenant-host-setup.sh` | runbook |

`afs-tenant-agent preflight` verifies all of it without changing anything — `node -v`, `npm -v`, `composer -V`, `git`
reachability of the template remote, `npm view @appfactory/checkpoint-client version`, the presence (not the value) of the Composer
auth, free disk, agent version — and records `preflight_ok_at` on the server row. **The selector (06 §6.2) refuses a server whose
last successful preflight is missing or older than a configured age**, so a server whose credentials expired fails at
selection, with a clear reason, instead of at `npm install` in step 6.

Rotating a token is a server-level action (re-run the initiation script with the new value, then `preflight`); no tenant is
touched. Tokens live only on the tenant hosts that need them, in root-only files, never in a tenant's home, `.env`, release
directory or repository.

## 7.3 The template source, on-host dependency install, and the template contract

**Tenants carry no `vendor/` and no `node_modules/`, and neither is ever copied between hosts.** The template repository ignores
`/vendor`, `/node_modules` and `/public/build` (already true of `w/www/.gitignore` and the `wwwafsportal_v1` copy), the copy
script does not copy them, and every host installs them itself, the same way, from the template's lockfiles. What the earlier
draft called a build-once artifact is dropped. What stays from it is the **versioned release directory and the `current`
symlink**, so a switch is atomic and a rollback is a symlink change:


```
 template repo (Gitea, pinned ref = commit SHA)                  host-local, per tenant, as root via the agent
   └ agent fetch-source  (read-only token, one-shot) ──▶ git clone/fetch → /home/<u>/websites/releases/<sha>
   └ agent install-release                                composer install --no-dev --optimize-autoloader
                                                          npm ci && npm run build       (private registry via root's ~/.npmrc)
                                                          link shared/ (.env, storage, uploads) · `current` → releases/<sha> · keep last 3
```

- **Install the same way everywhere:** `composer install --no-dev -o` and `npm ci` (lockfile-exact), then the build. **`npm ci` versus `npm install` is undecided** (`plans/TODOS.md`, 2026-10-07); today's `app_install.sh` runs `npm install`. Host-level
  caches (`~/.composer/cache`, `~/.npm`) make repeat installs on one server faster; they hold public packages only.
- **Pinned ref:** a deployment records `release_version` = the template commit SHA. New tenants get the current pinned SHA; a
  moved tenant gets **the SHA it already runs** (12 §12.4); the pin is changed by an explicit staff action.
- **No manifest file to build:** the template declares its deploy facts in a small file in the repository (`deploy.json`:
  `php_min`, `public_dir`, `health_path`, `env_template`, `post_install`, `rollback_safe`, `writable_paths`, `cron`), read at
  `install-release`.
- **Cost:** the install and build run per tenant, so a deploy takes minutes and needs the host's credentials and network. That is
  accepted; the `www`/`www-own` duplication that doubled it is gone (§7.5), and the credentials question is solved once, at
  server initiation (above), not per deploy.

**Which template is built (D1, as built).** The template is `packages/wwwafsportal_v1`. It is produced from `w/www` by
`packages/afsserver/bin/copy_portal_source.sh` (which copies `app`, `bootstrap`, `config`, `core`, `database`, `resources`, `routes`,
`.gitignore`, `composer.json`, `package.json`, `vite.config.js`, `tsconfig.json` and `_env/www/.env` as `.env`, and **never** `vendor/`,
`node_modules/` or `public/build`), and it lives in Gitea as **`appfactorystudio/afsportal_v1`** (lab: `https://gitea.10-0-0-243.sslip.io`).
A deploy pins a commit SHA of that repository. This plan does not read `wwwafsportal_v1` (project rule), so it states what the template
must satisfy and leaves it to the template's owner to confirm each line:

**Gitea settings the deploy reads** (Brain `brain_config` → `controller.gitea_3051`, passed to the scripts as `git_settings`): `url` and `domain`
are the **bare host** (`https://gitea.10-0-0-243.sslip.io`, `gitea.10-0-0-243.sslip.io`); `afspackage_repo` is the template clone URL;
`members_repo` is the base URL for per-tenant repositories; `one` (`appfactorystudio`) owns and reads the template, `two`
(`appfactorystudio2`) owns the per-tenant repositories; both tokens were checked against the lab Gitea. `app_install.sh` builds the tenant
remote from `git_settings.gitea_url` and `gitea_two.gitea_username`. In Path B the template is fetched with a read-only token for that one call.

| # | Requirement | Why |
|---|---|---|
| T1 | Runs from a `current` symlink; all configuration from `.env` and environment. **Unverified:** today the template's scripts assume `/home/<user>/websites/www/...` paths; until T1 is checked against the real template, Path A keeps that layout (and `www`/`www-own`) and the `releases/` + `current` layout is Path B only | Atomic switch and rollback |
| T2 | **Builds absolute URLs from the request host, or honours a per-vhost `APP_URL` override** (`SetEnv`/php-fpm env) | One docroot must serve two names; today's second copy `www-own` exists only to give the sites name a different `APP_URL` (F23) |
| T3 | `migrate --force` and the seed step are idempotent; the seed does not duplicate rows | Resumable deploys |
| T4 | A no-auth health endpoint returning 200 and the release version | Steps 9 and 12 |
| T5 | `composer install --no-dev -o`, `npm ci` and the build succeed unattended from a clean checkout on a prepared host (§7.2 initiation), and `vendor/`, `node_modules/` and `public/build` are git-ignored | Tenants install their own dependencies |
| T6 | Trusted-host / allowed-origin settings come from env, including both names | Both names, no redirect |
| T7 | Writes only under `storage`, declared uploads and `.env` | Release dirs are read-only and replaceable |
| T8 | The per-tenant tables it expects (`subdomain_properties`, `users_email`, …) are seeded by a declared command, not by shell `INSERT` text in the deploy script | Today those inserts live in `app_install.sh` and carry the DB password in plain SQL |

**Per-tenant Gitea repository.** `run_create_repo` creates a private repository per tenant, pushes the package into it and
clones it back. Nothing in the audit shows what reads that repository afterwards (an editor, a backup, an update path).
It is **not** in the critical path of this design; whether it is needed is **D13**. If it is, it becomes an asynchronous
"source backup" job after step 15, not a blocker for a live site.

## 7.4 Identifiers

Derived once, at acceptance, and stored on the tenant row (03 §3.7):

| Identifier | Rule | Why separate |
|---|---|---|
| `site_ref` | From the claim (03 §3.3) | The public DNS label |
| `systemuser` | `u` + `site_ref` (13 chars, letters/digits, starts with a letter) | Linux limit is 32; `--badname` is no longer needed |
| `db_name` | `<systemuser>_db` | ≤ 64 |
| `db_user` | `<systemuser>` | ≤ 32 |
| `db_password` | Generated by the agent, ≥ 24 random chars, **unique per tenant** (fixes F22) | Not known to the Brain |

## 7.5 One docroot, one file per web server, every name

`write-vhost` renders from a template, for **all hosts of the tenant at once**, in the **production file layout** (`afsdata/servers/live`):
the platform's own vhosts are two consolidated files (`appfactory.studio.zone.conf` for nginx, `appfactory.studio-vh.conf` for Apache) and **each
tenant has exactly one file per web server**, `<systemuser>-www.conf`, kept under `/mnt/node1/<systemuser>/server_configurations/{nginx,httpd}/`
and symlinked into `conf.d` (as `run_http_domain_server` does). Inside the tenant's file there is a `VirtualHost` / `server` block per name, all
with the same `DocumentRoot` (production's style); one block carrying `ServerAlias`es is equivalent.

```
Apache :808   <VirtualHost> per host: ServerName <host>   DocumentRoot …/<docroot>      (SetEnv APP_URL https://<canonical> = T2 fallback)
nginx  :443   server_name <hosts with a cert>  ssl_certificate …   proxy_pass http://localhost:808
nginx  :80    server_name <all hosts>          (301 to https for hosts that have a cert; proxy otherwise)
```

- **Hosts:** the sites name; for a **managed** claim also the apex and `www.<apex>`; for an **external** claim the same
  names (the member's own DNS decides whether traffic arrives).
- **Canonical host** (used for `APP_URL`): the custom domain once its certificate exists, otherwise the sites name. Both
  names always serve; neither redirects to the other (requirement: "accessible through the 2 domain names").
- **Safe write:** write to a temp file, `apachectl -t` and `nginx -t`, swap in, reload; on any failure restore the previous
  file and reload — a bad vhost never takes the server's other tenants down.
- **Host-conflict guard:** before writing, the agent greps every other tenant's vhost for the same `ServerName`/alias; a hit
  fails the step with `host_conflict`. The database already makes this impossible (unique active claim); this is the check
  on the machine that would otherwise serve the wrong site.
- **Unknown hosts** hit a catch-all default vhost that returns the platform's 404/parking page, never another tenant.

## 7.6 TLS

- **Sites name:** one **wildcard certificate for `*.sites.<zone>`** installed on every tenant host. Production today runs
  `certbot` per sites name (`app_install.sh`), which will hit Let's Encrypt's per-registered-domain issuance cap once
  enough sites exist under `appfactory.studio` (the published limit is on the order of fifty certificates per
  registered domain per week — confirm the current figure). A wildcard issued by DNS-01 (the Brain operates the DNS, 04
  §4.5/§4.9) and distributed by the Brain has no such ceiling. lab2 already has the wildcard (`certs/tenants.pem`,
  installed by `tenant-host-setup.sh`).
- **Custom domain:** `certbot` (HTTP-01) on the tenant host, **only after** the public-DNS check (step 12) shows the apex and
  `www` resolve to this server, with one certificate covering both names. Issuance is its own **deferrable** step (14): the
  site goes live on the sites name and plain HTTP immediately; the certificate retries with backoff for up to 72 hours,
  the UI shows "certificate pending: waiting for DNS", and on success the vhost is rewritten. A domain that never resolves
  to us simply never gets a certificate, and does not block the deploy.
- **Renewals:** `certbot.timer` on each host; the Brain also tracks `not_after` per certificate (P1) and alerts.
- **`LETSENCRYPT_CHECK`:** a boolean in each app's `.env` (`_env/<env>/.env`), read through `config('services.brain.letsencrypt_check')` in
  members and `config('brain.letsencrypt_check')` in www, and written into the job config as `letsencrypt_check`. `app_install.sh` skips
  certbot (logging that it did) unless it is `true`; a missing key means `true`, so production is unchanged. **`false` on `lab2`, `vagrant`,
  `vagrant-lan` and `local`; `true` on `dev` and `prod`.** In Path B the agent's `tls_custom` step and the sites-name step read the same flag.
- **lab2:** `*.10-0-0-243.sslip.io` is covered by the lab wildcard certificate (`certs/sslip-10-0-0-243.pem`), so a lab test domain's apex has
  HTTPS without certbot, and `<ref>.sites.test2` has it through `certs/tenants.pem`. `www.<domain>` is one label too deep for the wildcard
  and is HTTP-only. Any other custom name is HTTP-only (the lab CA cannot be issued for arbitrary names). Optional P2: the Brain runs
  `mkcert <name>` and pushes the pair.

## 7.7 The steps

State lives in `brain_deploy_steps`; the runner executes them in order, records each result, and resumes at the first step
not `done`. Each has a **compensation** used on cancel or unrecoverable failure.

| # | Step | Does | Compensation |
|---|---|---|---|
| 1 | `accept` | Preconditions (§7.1), select + reserve a server (06), quota `consume` | Release reservation, quota `release` |
| 2 | `prepare` | Identifiers (§7.4), the pinned template SHA, per-step payloads | — |
| 3 | `agent_ping` | Server reachable, agent version sufficient, last `preflight` fresh (§7.2) | — |
| 4 | `create_tenant` | System user, `/home/<u>/websites`, `/mnt/node1/<u>/…` directory set and ownerships (from `make_storage_directories`), database and user with a fresh password | Drop DB/user, remove dirs, `userdel` |
| 5 | `fetch_source` | Clone or fetch the template at the pinned SHA into `releases/<sha>` with the one-shot read token | Remove the release directory |
| 6 | `install_release` | `composer install --no-dev -o`, `npm ci`, build; link shared files; write `.env` from `deploy.json`'s template; switch `current` | Remove the release and links |
| 7 | `migrate_seed` | `key:generate` once, `migrate --force`, seed per T8, mark `seeded` | Drop the tenant database content |
| 8 | `write_vhost_http` | Vhosts for all hosts, HTTP only, with the safe-write rules (§7.5) | Remove the vhost, reload |
| 9 | `local_health` | `curl -H 'Host: <h>' http://127.0.0.1:808<health>` for each host; expect 200 and the version | — |
| 10 | `dns_sites` | Sites record → server IP (04 §4.5), wait for the `sites` publish job | Back to the parking address |
| 11 | `dns_zone` | Managed only: system records change (`@ A`, `www CNAME`); publish; wait | Back to previous system records |
| 12 | `public_check` | Resolve the sites name (and the apex, if managed and delegated) through `verify_resolvers`; fetch the health URL over the name | — (custom-domain misses are warnings) |
| 13 | `tls_sites` | Wildcard present ⇒ rewrite the vhost with :443 for the sites name | — |
| 14 | `tls_custom` | **Deferrable.** Certificate for the custom names once DNS is right (§7.6) | — |
| 15 | `register` | Tenant `active`, `release_version`, `members_websites` mirror, events, deployment `done` | — |
| 16 | `setup_mail` | P1: the behaviour of `write_dovecot_files`, carried into the agent unchanged | Remove the mail config for the tenant |

**Progress keeps the existing additive model (F29).** `ProgressService` adds each reported `step` to a running total and the job is
complete when the total reaches `max_steps`; every `record_progress` call reports `1`, and the final call with status `complete`
forces 100%. The number of calls on a normal run must therefore equal `max_steps` (19 today on the shell path: 1 from
`createWebsite`, 5 in `commands.sh`, 1 in `build_dns`, 12 in `app_install.sh`). **Whoever adds or removes a `record_progress` call
changes `max_steps` in `DomainController::createWebsite()` to match.**

- **Path A (shell pipeline, §7.0):** unchanged. The remote scripts call `record_progress`, which posts to
  `POST /api/v1/progress/record` (deliberately ungated, F28).
- **Path B (agent and runner):** the runner records `step = 1` after each completed step of the table above and initialises
  `max_steps` to the number of steps that apply; the remote agent never calls back, so the ungated endpoint is removed at
  cut-over (step 10). `brain_deploy_steps` still stores each step's result — that is what makes a deploy resumable — but it does not
  drive the percent.

**Failure handling.**

- A step that fails retries by its own policy (network steps three times with backoff; migrations never auto-retry
  after a partial failure).
- After the retries the deployment is `failed` but `resumable`; the member sees a clear message and a **Retry** (resumes at
  the failed step) or **Cancel** (runs compensations in reverse and releases the reservation and the quota slot).
- A runner that dies mid-step is detected by a heartbeat on the deployment row; another worker resumes it. This is the
  property `popen("nohup … &")` cannot provide.
- Step 11 or 12 failing after the site is up leaves the site reachable on its sites name and marks the custom name
  `pending_dns`; it does not tear the site down.

## 7.8 The runner

A queued job on its own queue (`deploy`) on the Brain (the lab2 `brain` VM already runs Redis; the queue driver is
confirmed in step 10 P0), run by a supervised `queue:work` worker with a per-server concurrency cap. It replaces:
the `popen` in `DomainController::createWebsite`, `background_runner.php --provision_new_website1`, the setuid
`afs-website` wrapper (and the `g++` compile of `wrapper.c` that every tenant currently performs), `commands.sh
--create-domain-on-server`, and the `expect` procedures `update_dns_servers` and `create_domain_on_remote_server`.

The Brain drives each remote step synchronously over SSH and records the result, so the earlier `callback_token`
idea is not needed in P0; if a future long-running step needs agent-initiated events (P2), a per-deployment signed token
is added then.

## 7.9 Enable, disable, retire

`disable-site` swaps the tenant's vhost to the platform's "this site is offline" page (data, DB and DNS untouched);
`enable-site` swaps it back. Used by: the member's Enable/Disable control (`website.ts`), feature 1's **retire = site
offline** (D1 there), account suspension (02 §2.10), and abuse holds. Per-host flags (the UI has a main and an alternate
toggle) are P1: the vhost lists only enabled hosts and disabled ones serve the offline page.

## 7.10 Delete, redeploy, rollback, move

- **Delete website** (not the domain): confirm → `disable-site` → sites record retired and system records removed (publish)
  → vhost removed → **archive** the database and files to the backup volume (`/mnt/node2`) with a retention period →
  drop database/user/home → tenant `removed` → quota `release` → `members_websites` inactive. Replaces the flag flip
  (F31).
- **Redeploy / template upgrade:** `kind = redeploy`: install the new release beside the old, migrate, health-check, then
  switch `current`; a failed health check switches back.
- **Rollback:** `switch-release` to the previous release; allowed only when the release manifest says `rollback_safe`
  (migrations are forward-only otherwise).
- **Move to another server:** a `kind = move` deployment with its own steps, a bounded freeze, a verified copy, the DNS
  cut-over, a hold period and rollback — see [12-tenant-move.md](12-tenant-move.md).

## 7.11 What the old scripts become

| Today | Becomes |
|---|---|
| `commands.sh --create-domain-on-server`, `step1–3` | The runner (§7.8) |
| `commands.expect update_dns_servers`, `move_zone_and_reload`, `update_named_config`, `reload_named` | DNS publisher + `afs-dns-agent` (04) |
| `app_install.sh build_dns` | Sites render (04 §4.5) |
| `app_install.sh make_storage_directories`, user/DB creation | Agent `create-tenant` |
| `app_install.sh run_create_repo` (clone, repo, copy, composer, npm, env, migrate, www/www-own) | Agent `fetch-source`, `install-release` (composer + npm on the host, **kept**), `write-env`, `migrate-seed`; one copy, not two |
| `app_install.sh` printf vhost JSON + `run-sample-vhosts.sh` | Agent `write-vhost` |
| `app_install.sh write_dovecot_files` | Agent `setup-mail` |
| `remote_commands.sh --check-dns-records`, certbot calls | Steps 12–14 |
| `background_runner.php` selection functions, `RunCommandBG1` | Selector (06); runner |
| `bin/afs-website`, `wrapper.c` | Removed |
| `members` copy of `core/scripts` | Retired for provisioning; one maintained copy of any remaining shared script (F41, **D2**) |

The old files stay in place, unchanged and unused, until step 10's cut-over sign-off; they are deleted then, not in P0.

## 7.12 Testing

- **Agent tests (per action)** against a throw-away container or the lab `web` VM: each action twice (idempotent), each failure
  injected (disk full, port busy, bad vhost) and its recovery.
- **Pipeline tests with a fake transport**, then on lab2 `web`: happy path; kill the worker at each step and confirm the
  resume; cancel at each step and confirm the compensation leaves no user, DB, vhost or DNS record.
- **Two-names test:** `curl --resolve` both names to the server, assert the same content, the right `APP_URL`, no redirect.
- **Host-conflict test:** pre-seed a vhost with the host; the step must fail with `host_conflict`.
- **Release test:** upgrade, forced bad health check ⇒ automatic switch back.
- **Preflight test:** remove the npm token from a lab host ⇒ `preflight` fails, the selector excludes the host with the reason, no deploy starts on it.

## 7.13 Phases

| Phase | Work |
|---|---|
| **P0** | Accept + reserve; agent with `ping/create-tenant/install-release/write-env/migrate-seed/write-vhost/health`; pinned template ref, `fetch-source`/`install-release` with on-host composer + npm and `deploy.json`; server-initiation script and `preflight`; runner with stored steps and resume; DNS steps 10–11 through the publisher; sites-name TLS (lab wildcard); both names on one vhost; progress from stored state; Delete/Cancel with compensation |
| **P1** | Custom-domain certificates and deferral; `setup-mail`; enable/disable and per-host toggles; redeploy and rollback; archive-on-delete; password-transport fallback; certificate expiry tracking; the Gitea repo decision (D13) |
| **P2** | Wildcard by DNS-01 automation; mkcert for lab custom names; agent-initiated events; template release channels (canary/stable pins); move follow-through automation |
