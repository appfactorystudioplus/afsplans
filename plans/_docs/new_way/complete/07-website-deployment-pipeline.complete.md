# 07 — Website deployment pipeline: completion report

> **Status: Path B (the agent and the runner) built and tested for P0, and from P1: enable/disable, delete, retry/cancel with compensation, resume after a dead runner. Not built: custom-domain certificates, mail set-up, redeploy/rollback through the runner, archive on delete, the password transport, per-host toggles. Path A (the shell pipeline the owner is fixing) was not touched.**
> Carried out 2026-10-09 against [07-website-deployment-pipeline.md](../07-website-deployment-pipeline.md). Nothing is committed. Code is in `w/www` (the Brain) and `lab2`; nothing in `w/members` belongs to this step.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

A website is deployed on an **active claim** by a runner that keeps its place in the database. It runs on the server chosen by step 06, serves **both** the sites name (`<site_ref>.sites.<zone>`) and the member's domain (apex and `www`) from **one docroot and one vhost file per web server**, and can be resumed, retried, cancelled (with compensation), disabled, enabled and deleted.

| Plan section | Built as |
|---|---|
| §7.1 accepting | `DeploymentService::accept()` — preconditions, the step-06 reservation and the 16 stored steps in one go; a repeated `client_key`, or a second request while one is open, **is** the first deployment; `DOM_E_NOT_FOUND` for a claim that is not the member's, `DOM_E_STATE` (not active), `DOM_E_DISABLED` (kill switch `domains.deploys_enabled`), `DOM_E_NO_CAPACITY`, `DOM_E_QUOTA` (through the `DeployQuota` seam) |
| §7.2 the tenant agent | `core/scripts/tenant/afs-tenant-agent` (bash, `jq`): `ping version preflight create-tenant fetch-source install-release write-env migrate-seed write-vhost health disable-site enable-site switch-release remove-tenant`; JSON on stdin, one JSON object on stdout, exit 0 / 1 / 2 (bad request) / 3 (`host_conflict`); a log per tenant in `/var/log/afs-tenant/<user>.log`; every action idempotent |
| §7.2 transport | `SshTenantTransport` — runs `afs-tenant-agent <action>` on the web server through `core/scripts/ssh/afs-ssh-run` (**bash drives, expect answers** the host-key question and the password prompt); **no keys**: the login (user and password) is read from the web server's own record (`ssh_user` or the first `json.users[]` entry, and that user's password); the password reaches the runner in its **environment only**, the request on **stdin**; the address is checked against the same CIDR allow-list as DNS before a process starts. See `complete/00_extras.md` E8 |
| §7.3 template | `fetch-source` clones the pinned ref, checks out the commit, **deletes `.git`** (no remote and no token stay in a release) and places it in `releases/<sha>`; `install-release` runs `composer install --no-dev -o` and, when the release has `package.json`, `npm ci` (`npm install` without a lockfile) and `npm run build` **on the host**; `deploy.json` is read for `php_min`, `public_dir`, `health_path`, `env_template`, `seed_command`, `rollback_safe`; `current` is switched with one atomic rename; the last 3 releases are kept |
| §7.4 identifiers | `systemuser = 'u' + site_ref` (13 characters), `db_name = <user>_db`, `db_user = <user>`; the **password is generated on the host** (≥ 24 characters, one per tenant), kept in a root-only `.credentials` (600), written into the tenant's `.env` (640 root:\<user\>), and **never printed, logged, or returned** — the Brain has no column for it |
| §7.5 vhost | one `<user>-www.conf` per web server under `/mnt/node1/<user>/server_configurations/{nginx,httpd}/`, symlinked into `conf.d`; one `VirtualHost` with every name as an alias of the same `DocumentRoot`, `SetEnv APP_URL` for the canonical name; **safe write** (previous files kept, `apachectl -t` and `nginx -t`, restored byte for byte on failure); **host-conflict guard** over every other tenant's file |
| §7.6 TLS | `tls_sites` rewrites the vhost with `:443` for the sites name when `domains.deploy.sites_cert` is set (the lab wildcard); **a failure there is a warning, never a failed deployment** |
| §7.7 steps | `DeployRunner` — the 16 steps, stored in `brain_deploy_steps`, resumed at the first one not done, each with its own retry policy; a step that is not `retryable` (a migration) is never repeated automatically |
| §7.7 dead runner | `heartbeat_at` on the deployment; a deployment `running` with a heartbeat older than `domains.deploy.stale_after_seconds` is taken over by the next `run()`; the claim is one conditional `UPDATE`, so two runners cannot both run it |
| §7.8 runner | `DeployWebsiteJob` (queue `deploy`) **and** `domains:deploys`, scheduled every minute, which runs or resumes anything without a runner — so deployments make progress with **no queue worker at all** |
| §7.9 | `setOnline()` — `disable-site` swaps the vhost for the offline page (data, database and DNS untouched), `enable-site` swaps it back |
| §7.10 delete | a deployment of kind `remove`: `disable_site` → `dns_release` (tenant `removed`, the names go back to the parking address) → `remove_tenant` (vhost, database, user, home) → `finish` |

## 2. Files

All paths under `C:\projects\vagrant\afs-eco\w\www` unless stated.

- `core/scripts/tenant/afs-tenant-agent` — the agent (canonical source).
- `app/Services/Brain/Deploy/` — `TenantTransport`, `SshTenantTransport`, `DeployRunner`, `DeploySteps`, `DeploymentService`, `StepFailure`, `DeployQuota`, `NullDeployQuota`.
- `app/Jobs/DeployWebsiteJob.php`, `app/Console/Commands/DomainsDeploys.php` (+ the schedule in `routes/console.php`), bindings in `AppServiceProvider`.
- `database/migrations/2026_10_08_000006_add_deployment_runner_columns.php` — `heartbeat_at`, `cancel_requested`, `member_reference_num` (indexed), `warnings` on `brain_website_deployments`. Additive, guarded.
- `config/domains.php` — `deploys_enabled`, `tenant_agent`, `deploy` (template, certificate, retry, wait and stale settings). `_env/lab2/.env` — the lab values; `DOMAINS_TEMPLATE_REF` is **empty on purpose**.
- Tests: `tests/Feature/Brain/Domains/Deploy/DeployPipelineTest.php` (32), `DeployAgentLabTest.php` (6, opt-in), `LabDeployTest.php` (1, opt-in), `tests/Support/FakeTenantTransport.php`, `FakeDnsLookup::setAddresses()`.
- `C:\projects\vagrant\lab2` — `web/afs-tenant-agent` (copy), `web/install-tenant-agent.sh`, a call to it at the end of `provision/tenant-host-setup.sh`.

## 3. Decisions made on the way

- **The tenant becomes `active` at step 10 (`dns_sites`), not at 15 (`register`).** The sites entry and the apex follow a claim's server only while its tenant is `active` (step 04, `DomainDnsSync::siteAddress`), and the public check (12) needs the name to point at the server. So the site is `active` once it is healthy on the host and has been published; `register` records the release and the event. Cancel puts it back to `provisioning` and re-publishes the parking address.
- **`write-env` runs inside step 6** (after `install-release`), because the template's `.env.example` comes from the release and the next step (migration) needs the file.
- **Progress is computed from the stored steps** (`finished / total`, 100 when done), not kept as a counter someone has to keep in step with `max_steps`. The ungated `POST /api/v1/progress/record` is not used by this path.
- **A repeated accept returns the first deployment** (also when a different `client_key` arrives while one is open, or after it failed and is waiting for Retry) instead of a 409: the member never sees an error for a second click.
- **Hosts:** the sites name always; for any claim whose name is not itself under the sites zone also the apex and `www.<apex>` — **managed or external**, as the plan says (the member's own DNS decides whether traffic arrives). Neither redirects.
- **Server rows are written by the first agent check** (step 3): `agent_version`, `last_heartbeat_at`, `preflight_ok_at`/`preflight_detail`, and `health_state` (`healthy` / `degraded`). This is the writer step 06 was waiting for; the selector's reachability, preflight and agent-version filters can now be switched on (`DOMAINS_SELECT_REQUIRE_*`) — **I did not switch them on**, because a server that has never been deployed to has no check yet and would be excluded.
- **A failed preflight marks the server `degraded` and clears `preflight_ok_at`**, so the selector (once its filters are on) keeps new sites off it with the reason.
- **Dispatch:** with the `sync` queue the job would run the whole deploy inside the web request, so `accept()` does not dispatch then and leaves the deployment for `domains:deploys` (one minute at most). Any other queue driver gets the job at once.
- **The agent's `fetch-source` accepts a local path as the template URL** (git clones it); that is how the lab tests run without a Gitea.

## 4. Where the build differs from the plan text

1. **Path B only.** None of the Path A items (the owner's shell pipeline) was done or changed; the old scripts, `createWebsite()` and `selectTenantServer()` in `members` are untouched and still what the old UI calls.
2. **Not built (P1/P2):** custom-domain certificates (`tls_custom` is a recorded `skipped` step: "deferred work"), `setup_mail` (a recorded `skipped` step), redeploy and rollback **through the runner** (the agent has `switch-release`; no runner kind uses it), archive-on-delete (delete removes the data; there is no archive step), per-host enable/disable, the password transport, certificate-expiry tracking, the Gitea repository decision (D13), move (step 12), DNS-01 wildcard automation.
3. **`pending_dns` for the custom name** is a **warning** on the deployment (`public_check` records "example.com does not resolve to the server yet"), not a separate state.
4. **No `waiting_capacity`:** with no server qualifying, `accept()` is refused with `DOM_E_NO_CAPACITY` (as in step 06).
5. **Quota:** `consume` at acceptance and `release` on cancel/delete go through the `DeployQuota` seam; the default does nothing. There is no Brain-side count (D11) and `members` has no allocation client to report to, so nothing enforces a website limit on the Brain yet.
6. **Server initiation is a check, not an installer:** `preflight` reports what is missing (`jq php git composer curl mysql`, the web servers, `node`/`npm` and the registry token when asked, the template remote, disk, the database). The lab `initial_install.sh` block that puts node on root's PATH is the owner's (Path A work list) and was not touched.

## 5. Verification

Real MariaDB (`10.0.0.243`, `testdb_phpunit_www`) for everything; tenant hosts and DNS in memory unless stated.

| Result | |
|---|---|
| `DeployPipelineTest` | **32 tests, all passing** (209 assertions in the run that found one wrong expectation of mine — the apex address after a cancel — fixed; the behaviour was right) |
| Accept | a repeated click and a second request are one deployment and one reservation; the reservation records why that server; each precondition refuses with its code and leaves no row behind; the quota is consumed at acceptance and given back on cancel and on a quota refusal |
| Run | all 16 steps recorded; the vhost lists the sites name, the apex and `www`; the tenant row has the identifiers and no password column; no step, event or answer holds the read token or a password; the token reaches `fetch-source` and no other call; the member sees no server address, reference or selection |
| DNS | the sites entry follows the server; the steps wait for the publisher; the apex follows the entry; an external domain skips the zone step; a cancel puts the sites entry back on the parking address and the apex loses its address |
| Retry and failure | a transient failure is retried by the step's own policy and then succeeds; a persistent one ends `failed` + `resumable` at that step only and **Retry resumes there** (earlier steps are not run again); a migration is tried once; a host conflict is not retried; a failed preflight degrades the server and nothing is created on it; an unreachable host is retried then reported |
| Resume | a fresh heartbeat is never taken over (`busy`); a stale one is, and it **resumes, it does not start over**; an unexpected defect ends the deployment failed and resumable, never stuck `running` |
| Cancel / delete | compensation removes the tenant, releases the slot and gives the allowance back, and the claim can be deployed again; a cancel of a running deployment takes effect between steps; a finished website cannot be cancelled, only deleted; delete takes the site offline first, returns the names to parking and frees the slot |
| Ownership | status, retry, cancel, delete, enable and disable by another member are all `DOM_E_NOT_FOUND` (404) |
| **`DeployAgentLabTest` — the real agent on the lab web VM** | **6 tests, all passing** (111 assertions in the first run; the one failing expectation was mine — `composer install` creates `vendor/autoload.php` even with no packages — and that test was re-run). Bad requests are refused before anything changes; create is idempotent and the password is ≥ 24 characters, root-only, works for its own database and not for another, differs per tenant, and is **absent from every answer and from the log**; fetch/install/env are idempotent, no `.git` is left in a release, a second `write-env` keeps `APP_KEY` and cannot be made to set the database password; the vhost serves all three names (HTTP 200 through Apache `:808`), a second tenant asking for one of them gets `host_conflict` (exit 3) and **nothing of the first changes (md5 of both files)**, a certificate nginx cannot load gives `vhost_invalid` and **both files are restored byte for byte** while the other names keep serving, a first vhost that is bad leaves no file; health (right version, wrong version, 404), disable → offline page → enable, `switch-release`; preflight lists what is missing (`node` on this host) and passes without that requirement; remove is complete (user, database, database user, vhost, `/mnt/node1/<user>`, home) and repeatable |
| **`LabDeployTest` — the real runner, transport and agent on the lab web VM** | **1 test, 27 assertions, passing.** The Brain's runner drives `afs-tenant-agent` over ssh (the real `SshTenantTransport`, sudo, JSON on stdin) through all steps; both names answer **from this PC through nginx :80 → Apache :808**, each as itself (`Host` echoed), with the pinned release, no redirect; an unknown name never lands on the tenant; running the whole deployment again changes nothing; disable shows the offline page, enable restores; delete removes the user, database and vhost; the password is in no Brain table and not in the host log |
| Whole Domains folder + guardrail, one run | **230 tests, 3,421 assertions, 8 skipped** (the opt-in lab tests: DNS 1, tenant agent 6, deploy 1) |
| Lab database | migration `…000006` applied to `testdb_appfactorystudio` after a copy of `brain_website_deployments` (`_backup_20261009_brain_website_deployments`, empty) |

## 6. Not verified, honestly

- **The real template** (`afsportal_v1`): composer with the private packages, `npm ci` and the build, the migrations, the seeder, and whether it runs from a `current` symlink and builds absolute URLs from the request host (T1, T2, T6). The lab web VM has **no node/npm**, so a real deploy there stops at preflight with that reason. The lab tests use a template with a `composer.json` that has no dependencies and no `package.json`.
- **Certbot / custom-domain certificates** and **the lab wildcard on a real deploy**: `tls_sites` was tested with the certificate path through the fake host and with a rejected certificate on the real host; a real deploy with `DOMAINS_SITES_CERT` set was not run.
- **Real DNS in the deploy flow**: the deploy tests use an in-memory DNS server; the DNS agent has its own lab test (step 04) but the two were not run together. The public check resolves through the fake lookup.
- **A real queue worker**: the runner was run directly and through its command's logic (`runnable()` / `run()`); `domains:deploys` is registered and scheduled but was **not executed by the scheduler**, and `DeployWebsiteJob` was not run by a worker.
- **Concurrency of two runners** is proven by the conditional claim (a second `run()` answers `busy`), not with two processes.
- **Migrations that fail half way** are not retried automatically (tested); recovering from one by hand is the operator's.
- **Production servers over the password runner:** the runner was proven on the lab (Brain VM → web VM, throwaway user); no production server was contacted, the production Brain's OpenSSH/expect versions are unknown, and the production server records must hold a working ssh user and password.

## 7. To do next, in order

1. **Step 08 UI exercised in a browser** (the screens in this step's sibling report), then the cut-over of `createWebsite()` to the Brain (step 10): `members` stops selecting a server and writing job files.
2. **Install node/npm and the registry token on the lab web VM** (server initiation) and run the real template once; switch on `DOMAINS_SELECT_REQUIRE_PREFLIGHT` / `REQUIRE_REACHABILITY` / `REQUIRED_AGENT_VERSION` once every tenant host has had a check.
3. **Pin a template**: set `DOMAINS_TEMPLATE_REF` (and the read-only `DOMAINS_TEMPLATE_TOKEN`) — until then every deploy is refused with "no template is published".
4. Custom-domain certificates (`tls_custom`), `setup_mail`, redeploy/rollback on the runner, archive on delete, per-host toggles — the P1 list in §4.
5. Step 12 reuses `reserve()`, the agent and the runner for moves.
