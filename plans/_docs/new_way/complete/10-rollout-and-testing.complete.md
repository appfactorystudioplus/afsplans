# 10 — Rollout and testing: completion report (website deployment)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **website deployment** half; the **domain name management** half is [../../domain_name_management/complete/10-rollout-and-testing.complete.md](../../domain_name_management/complete/10-rollout-and-testing.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: the P0 checks are answered in writing (§2); the per-member cut-over, the observability and the production runbook are built/written; the test matrix is mapped to real tests with its gaps named (§4). Nothing is cut over, nothing was run on production, and the "P0 done" definition is NOT yet met (§8).**
> Carried out 2026-10-09 against [10-rollout-and-testing.md](../10-rollout-and-testing.md). Nothing is committed. Code is in `w/www` and `w/members`.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

| Plan section | Built as |
|---|---|
| §10.2 P0 checks | answered below, each with how it was checked |
| §10.5 test matrix | mapped to the tests that exist, with the gaps (§4) |
| §10.7 runbook | written in full, §6 |
| §10.8 rollback | the per-member switch (set `new_flow.*` back; within a minute the member is on the old flow), the kill switches (`claims_enabled`, `purchases_enabled`, `deploys_enabled`, `publish_enabled`, all Brain settings), and the properties the plan lists (stage → check → rename; vhost restore; additive tables) — all built in earlier steps and verified there |
| §10.9 observability | `php artisan domains:health [--days=7] [--json] [--log]` and `App\Services\Brain\Domains\DomainHealth`: counters (verification success rate and median time to verify; publish latency and failure rate; deploy duration per step; failures by step and by server; reservations released; drift found) and the alerts of the plan (publish failing > 15 min; a deployment running with no heartbeat for 5 min; an active server that is degraded or draining; four invariant checks). Exit status 1 when any alert exists; scheduled every 15 minutes with `--log`, which writes each alert to the application log |
| §10.10 | unchanged; items restated in §7 with what this step learned |
| §10.11 | assessed in §8 |

(§10.4 adopting production data and §10.6 the per-member cut-over are in the domain-name report.)

## 2. The P0 checks (10 §10.2)


| # | Result | How it was checked |
|---|---|---|
| C2 | **No, as the plan assumed Redis.** The lab Brain's queue is `database` (`QUEUE_CONNECTION=database`); Redis runs but is not the queue. **There is no queue worker and no scheduler on the lab Brain** (no cron entry for any user, no `queue:work`, no `schedule:run`). Deployments do not need a worker (`domains:deploys` runs them every minute) — but *nothing scheduled runs at all* until the scheduler exists. `lab2/provision/brain-worker.sh` is the fix (written, dry-run tested, not installed) | `systemctl`, `crontab -l` for three users, `ps` on the Brain VM |
| C3 | **The widget calls `GET /api/v1/progress/{key}` and `POST /api/v1/progress/record`** (the client is built on the base `/api/v1/progress`). Step 08's surface mode does not use the widget; the proxy is still to do | `resources/js/afslibs/services/progress.ts` in members |
| C4 | **The Brain stores no server monitoring reports** (monitoring is read by the phone, step 06). Server health is `health_state`, written by the first agent check of a deploy (step 07) | step 06 report |
| C5 | **Brain VM: yes** — `node`, `npm`, `composer` resolve for non-interactive root; the npm token is in root's `.npmrc`. **Web VM: no `node`/`npm`** (token present, `composer` present). The Composer GitHub token was not checked on either | ssh as root on both VMs |
| C6 | **Not checked.** The template (`packages/wwwafsportal_v1`) is out of scope for this work; T1–T8 are the template owner's to confirm | — |
| C8 | **50 new certificates per registered domain per 7 days** (refills 1 per 202 minutes), 5 per exact set of names per 7 days, 5 failed validations per identifier per hour, 300 orders per account per 3 hours. The wildcard-versus-per-site argument of 07 §7.6 stands | letsencrypt.org/docs/rate-limits |
| C10 | **Only the shell scripts wrote `POST /api/v1/progress/record`** (the plan's reading, F28); the widget reads `GET /api/v1/progress/{key}`. At cut-over remove only the writer route | C3 plus the plan's audit |
| C11 | **Not checked** (template layout `releases/<sha>` + `current`, 07 T1). Path B's lab tests use a stand-in template that runs from `current` | — |

> **§3 (cut-over and rollback, concretely: the per-member switch)** is in the domain-name report: [../../domain_name_management/complete/10-rollout-and-testing.complete.md](../../domain_name_management/complete/10-rollout-and-testing.complete.md).

## 4. The test matrix (10 §10.5) mapped to what exists


| Area | Tests | Gap |
|---|---|---|
| Selector | `HttpServerSelectorTest` (18, incl. 8 real processes against 5 slots) | the plan's 20 processes: 8 used |
| Agent | `DeployAgentLabTest` (6, real web VM), `DnsAgentScriptTest`, `LabSshRunnerTest` (9, real runner) | injected disk-full/port-busy failures not run |
| Pipeline | `DeployPipelineTest` (32), `LabDeployTest` (real VM) | kill at **every** step and cancel at every step: sampled, not all 16 |
| Transport | `DomainsSurfaceTest` (13: unsigned, replayed, other-app key, ownership 404), members `DomainSurfaceControllerTest` (11), `DomainFlowTest` (6) | — |
| Guard | `SeedLabAndFleetGuardTest` (5), `SshDnsTransportTest`, `SshLoginTest` | — |
| Observability | `DomainHealthTest` (8) | alert delivery beyond the log: not built |
| UI | `surface.test.ts` (8, the pure rules) and a type-check | **no browser run; the manual script of the plan was not executed** |
| Tenant move | — | step 12 not built |

## 5. Where the build differs from the plan text

2\. **The alerts go to the application log**, not to the Brain's notification path and not to a staff screen — I did not find or build that path. Certificate expiry is reported as "not tracked yet".
3\. **§10.7 is a runbook, but it has not been executed on any production server.**

## 6. Production bootstrap runbook (10 §10.7), as it should now be run

Everything below is a Brain setting (Controller > Domains) unless it says otherwise. `php artisan domains:settings` must report `0 missing` before step 4.

2\. **Each web server.** Server initiation (07 §7.2): `node`/`npm` on root's non-interactive PATH, the npm and GitHub tokens, then `afs-tenant-agent preflight` must pass; install the agent (`lab2/web/install-tenant-agent.sh`); install the wildcard certificate for the sites zone and the catch-all (`lab2/web/install-parking.sh <sites zone> <cert> <key>`); the server's record in `brain_http_servers` needs `ssh_user` (or `json.users[]`) and the password.
3\. **The Brain.** Put the code on the Brain; set the settings: `env`, `platform_ns`, `reserved_*`, `sites_zone`, `platform_zone_parent`, `parking_ipv4`, `allowed_server_cidrs` (**the production fleet — this is the guard**), `dns_agent.*`/`tenant_agent.*` (including `ssh_runner`, the path of `core/scripts/ssh/afs-ssh-run` on the Brain), `deploy.template_url`/`template_ref`/`template_token`, `deploy.sites_cert`/`sites_cert_key`, the registrar settings. Run `provision/brain-worker.sh` (queue worker + the minute scheduler). Keep `publish_enabled` **off**, `new_flow.all` off and `new_flow.members` empty.
4\. **Dry runs.** `domains:settings`; `domains:seed-lab` is lab-only and refuses elsewhere; `domains:backfill-claims --dry-run` then staff resolve the conflicts then `--apply`; `domains:backfill-tenants`; add the unique indexes (the migration refuses on duplicates); `domains:publish --dry-run` prints what would be written; turn `publish_enabled` on and watch the first adopted publish (it refuses to drop a line it did not write); `domains:health` shows no alert.

## 7. The "cannot be proven from here" list (10 §10.10), updated

3\. Let's Encrypt issuance for a real custom domain (custom-domain certificates are not built).
4\. The template with two hostnames on one docroot (T2, T6) — the lab tests use a stand-in.
5\. The production DNS and web servers: `named` version and configuration, OpenSSH and `expect` versions on the Brain, whether the stored ssh logins work (the lab's do).
7\. **New:** every browser screen of step 08.

## 8. Definition of done (10 §10.11)

- **P0 done** requires lab acceptance 09 §9.9 items 1–6, the guard demonstrated, the P0 checks answered, and a completion doc per step carried out. **Met:** the guard (demonstrated), the checks (written, C6 and C11 not checkable here), completion docs for 02–10. **Partly met:** items 1–4 proven on the real lab; 5–6 proven on the real web VM with a stand-in template and, for the kill/resume, only with the in-memory host. **Not met until the code is on the lab Brain VM and the real template runs.**
- **P1 done:** not started (backfill dry run on production data, the bootstrap on one production DNS and one web server, a staff account cut over end to end).

## 9. Verification

One run of the whole Domains folder, the migration guardrail and the config-rules tests, all opt-in lab tests on: **272 tests, 3,897 assertions; 270 passed in that run.** The two that failed were both lab-DNS tests and the cause was a defect in the NEW `LabConnectFlowTest`: it left its line in the shared sites list on the DNS VM, and the next test's first publish (adopt mode, which refuses to drop a line it did not write) failed. The test now releases its claim at the end (which also proves 09 item 9 through the flow) and its tear-down removes its line; the stale line was removed, and the three lab-DNS runs (connect, publish, connect again, in that order) then passed: 3 tests, 71 assertions, and the sites list is unchanged afterwards. The whole folder was **not** run a second time after that fix. Members: `DomainSurfaceControllerTest` + `DomainFlowTest` 17 tests, 79 assertions; `surface.test.ts` 8 tests; type-check of the touched files clean (the project `tsconfig` needs a one-option override with the installed TypeScript, see `complete/08-…`).

## 10. To do next, in order

1\. Put the code on the lab Brain VM; install the worker/scheduler; run the cut-over switch for one lab member and look at the screens in a browser.
3\. The template owner confirms T1–T8 (C6, C11); node on the lab web VM; run the real template.
5\. Alert delivery (a staff screen or the Brain's notification path) and certificate-expiry tracking.
