# 10 — Rollout and testing: completion report (domain name management)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/10-rollout-and-testing.complete.md](../../new_way/complete/10-rollout-and-testing.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: the P0 checks are answered in writing (§2); the per-member cut-over, the observability and the production runbook are built/written; the test matrix is mapped to real tests with its gaps named (§4). Nothing is cut over, nothing was run on production, and the "P0 done" definition is NOT yet met (§8).**
> Carried out 2026-10-09 against [10-rollout-and-testing.md](../10-rollout-and-testing.md). Nothing is committed. Code is in `w/www` and `w/members`.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

| Plan section | Built as |
|---|---|
| §10.2 P0 checks | answered below, each with how it was checked |
| §10.4 adopting production data | built in steps 02/03 (`domains:backfill-claims --dry-run/--apply`, `domains:backfill-tenants`, the additive migrations, the unique indexes that refuse on duplicates, the adopt-mode first publish); nothing new here. **Not done:** a dry run against a copy of *production* data (there is none on this machine) |
| §10.5 test matrix | mapped to the tests that exist, with the gaps (§4) |
| §10.6 cut-over | **Per member, decided by the Brain's settings.** The plan's `domains.new_flow` per-member flag became `new_flow.all` / `new_flow.members` in the Brain settings (no `.env`); members asks the Brain (`POST /domains/flow`, signed), remembers the answer for 60 s, and a Brain that cannot be asked leaves the member on the old flow (remembered for 10 s). The four shared URLs (list, add, delete, search) go through `DomainFlowController`, which picks the old `DomainController` or the Brain pass-through **for that member**; the Brain-only URLs sit behind the `domains.new_flow` middleware (a 404 for a member not switched over); the old-only URLs are untouched. The screens switch on the `api` field of the list, so one UI build serves both. **This replaces the `DOMAINS_USE_BRAIN_SURFACE` members flag of step 08** (see `complete/00_extras.md` E9) |
| §10.7 runbook | written in full, §6 |
| §10.8 rollback | the per-member switch (set `new_flow.*` back; within a minute the member is on the old flow), the kill switches (`claims_enabled`, `purchases_enabled`, `deploys_enabled`, `publish_enabled`, all Brain settings), and the properties the plan lists (stage → check → rename; vhost restore; additive tables) — all built in earlier steps and verified there |
| §10.9 observability | `php artisan domains:health [--days=7] [--json] [--log]` and `App\Services\Brain\Domains\DomainHealth`: counters (verification success rate and median time to verify; publish latency and failure rate; deploy duration per step; failures by step and by server; reservations released; drift found) and the alerts of the plan (publish failing > 15 min; a deployment running with no heartbeat for 5 min; an active server that is degraded or draining; four invariant checks). Exit status 1 when any alert exists; scheduled every 15 minutes with `--log`, which writes each alert to the application log |
| §10.10 | unchanged; items restated in §7 with what this step learned |
| §10.11 | assessed in §8 |

## 2. The P0 checks (10 §10.2)


| # | Result | How it was checked |
|---|---|---|
| C1 | **Yes.** MariaDB 10.5.29 supports stored generated columns with unique indexes; five are in use and enforced (`active_fqdn`, `member_live_key`, `active_key`, `active_claim`, `open_tenant_key`). Production's engine was **not** checked | `information_schema` on the lab; the migrations and tests ran |
| C2 | **No, as the plan assumed Redis.** The lab Brain's queue is `database` (`QUEUE_CONNECTION=database`); Redis runs but is not the queue. **There is no queue worker and no scheduler on the lab Brain** (no cron entry for any user, no `queue:work`, no `schedule:run`). Deployments do not need a worker (`domains:deploys` runs them every minute) — but *nothing scheduled runs at all* until the scheduler exists. `lab2/provision/brain-worker.sh` is the fix (written, dry-run tested, not installed) | `systemctl`, `crontab -l` for three users, `ps` on the Brain VM |
| C7 | **Name.com's base URLs are as the plan says** (`https://api.name.com`, sandbox `https://api.dev.name.com`), credentials by HTTP Basic, **and the sandbox username is the normal username with `-test` appended, with its own token**. **Update 2026-10-09: the driver was moved to CORE v1 (`complete/00_extras.md` E10) and the sandbox accepted the lab's stored credentials.** *Original finding:* Name.com's current API is **CORE v1** (`/core/v1/...`); the older **v4 API, which `NameComDriver` uses (`/v4/domains…`), is documented as scheduled for retirement in 2026** — this is October 2026. The driver must be checked against CORE v1 and the sandbox before any real purchase. The two URLs and the auth scheme were read from Name.com's documentation (a search result summary and the legacy docs page); the v1 paths were **not** compared with the driver call by call | web search / docs fetch |
| C9 | **BIND 9.16.50-RH.** `check-names master ignore;` is set. `rndc` needs root (so the agent runs as root or via sudo, as it does). `put-zone`, `remove-zone`, `put-include`, `rebuild-conf` and `rndc reconfig`/`reload <zone>` all worked in the lab tests | `named -v`, `named.conf`, steps 04 and 09 tests |

## 3. Cut-over and rollback, concretely

- **Start:** nobody is on the new flow (`new_flow.all = false`, `new_flow.members = []` — the editor's example values and the lab document).
- **Staff first:** add staff member references to `new_flow.members` in the Brain settings editor. Within a minute their list answers `api: "brain-surface"` and they get the new screens; everyone else is unchanged.
- **Everyone:** set `new_flow.all` on. **Back:** turn it off / remove the member; within a minute they are on the old flow. Websites already built keep working (nothing is redeployed). The `members_websites` mirror is written for new-flow members only.
- A member who is on the new flow and an old-flow `create-website` call are both possible URLs; the new screens use the new URL. The old URLs are removed only after the clean week (10 §10.6 step 5), which this step did not do.

## 4. The test matrix (10 §10.5) mapped to what exists


| Area | Tests | Gap |
|---|---|---|
| Normaliser / refusals | `DomainNameNormalizerTest` (7), `DomainClaimsTest` | — |
| Claim states, events | `DomainClaimsTest` (25) | — |
| Activation race | `DomainActivationRaceTest` (two real processes against the unique index) | the plan's 20 parallel activations: 2 processes were used |
| Verifier | `DomainClaimsTest`, `DigDnsLookupTest` (5), **`LabConnectFlowTest` (real authoritative DNS, real `dig`)** | stale secondary, recursion-only answer not injected on the lab |
| Uniform responses | `DomainClaimsTest`, `LabConnectFlowTest` (same shape for A and B) | the **timing** class was not measured |
| Renderer / serial / publisher / records | `ZoneRendererTest` (10, goldens checked with `named-checkzone`), `DnsSerialTest`, `DnsPublishingTest` (22), `ZoneRecordsTest` (14), `DnsAgentScriptTest` (17), `LabDnsPublishTest` | serial property over 1,000 edits not run (3 tests) |
| Purchase | `DomainPurchaseTest` (21), `RegistrarDriversTest` (9) | a real registrar: no |
| Transport | `DomainsSurfaceTest` (13: unsigned, replayed, other-app key, ownership 404), members `DomainSurfaceControllerTest` (11), `DomainFlowTest` (6) | — |
| Guard | `SeedLabAndFleetGuardTest` (5), `SshDnsTransportTest`, `SshLoginTest` | — |
| Settings | `DomainSettingsTest` (11) — incl. a scan that no domains code reads `.env` or `config('domains.*')` directly | — |
| Observability | `DomainHealthTest` (8) | alert delivery beyond the log: not built |
| UI | `surface.test.ts` (8, the pure rules) and a type-check | **no browser run; the manual script of the plan was not executed** |
| Backfill | `DomainClaimBackfillTest` (6), `DeployDataModelTest` (9) | a copy of production data: none |

## 5. Where the build differs from the plan text

1\. **The cut-over flag is a Brain setting pair, not `domains.new_flow` in `.env`**, and it is decided per request by the Brain, so members needs no change to move a member.

2\. **The alerts go to the application log**, not to the Brain's notification path and not to a staff screen — I did not find or build that path. Certificate expiry is reported as "not tracked yet".

3\. **§10.7 is a runbook, but it has not been executed on any production server.**

4\. The plan's *"kill switches default off until the dry runs pass"* is satisfied by the settings: `claims_enabled`, `purchases_enabled`, `deploys_enabled` and `publish_enabled` are Brain settings; the editor's example values are on for the first three and **off for `publish_enabled`**.

## 6. Production bootstrap runbook (10 §10.7), as it should now be run

Everything below is a Brain setting (Controller > Domains) unless it says otherwise. `php artisan domains:settings` must report `0 missing` before step 4.

1\. **Each DNS server.** Install `afs-dns-agent` (`lab2/dns/install-agent.sh` shows the steps: `/usr/local/bin`, `/var/named/.staging`, `.trash`, `zones/`); add `include "<named dir>/member-zones.conf";` only if `named_conf_mode` is `include`; confirm `named-checkconf` and `rndc status`. The server's own record in `brain_dns_servers` needs `username` and `password` of a login that can run the agent (root, or a user with `sudo -n` for it and the setting `dns_agent.sudo` on). Confirm the OpenSSH client on **the Brain** is 7.6 or newer (`StrictHostKeyChecking=accept-new`) and that `expect` is installed (`command -v expect`).
3\. **The Brain.** Put the code on the Brain; set the settings: `env`, `platform_ns`, `reserved_*`, `sites_zone`, `platform_zone_parent`, `parking_ipv4`, `allowed_server_cidrs` (**the production fleet — this is the guard**), `dns_agent.*`/`tenant_agent.*` (including `ssh_runner`, the path of `core/scripts/ssh/afs-ssh-run` on the Brain), `deploy.template_url`/`template_ref`/`template_token`, `deploy.sites_cert`/`sites_cert_key`, the registrar settings. Run `provision/brain-worker.sh` (queue worker + the minute scheduler). Keep `publish_enabled` **off**, `new_flow.all` off and `new_flow.members` empty.
4\. **Dry runs.** `domains:settings`; `domains:seed-lab` is lab-only and refuses elsewhere; `domains:backfill-claims --dry-run` then staff resolve the conflicts then `--apply`; `domains:backfill-tenants`; add the unique indexes (the migration refuses on duplicates); `domains:publish --dry-run` prints what would be written; turn `publish_enabled` on and watch the first adopted publish (it refuses to drop a line it did not write); `domains:health` shows no alert.
5\. **Name.com.** The driver uses the legacy v4 API (C7): move it to CORE v1 and run a **sandbox** purchase (sandbox username has `-test`, its own token) before a real one; set `purchase_direct_payment_ref` and confirm the Direct Payments product accepts a dynamic item.
6\. **Cut over** (§3): staff in `new_flow.members`, then everyone; a clean week; then the removals of 10 §10.6 step 5.

## 7. The "cannot be proven from here" list (10 §10.10), updated

1\. A real registrar purchase end to end — and now also the **v4 → CORE v1** question (C7).
2\. Nameserver propagation through real recursive resolvers.
5\. The production DNS and web servers: `named` version and configuration, OpenSSH and `expect` versions on the Brain, whether the stored ssh logins work (the lab's do).
6\. Mail for a domain moved to the platform (out of scope).
7\. **New:** every browser screen of step 08.

## 8. Definition of done (10 §10.11)

- **P0 done** requires lab acceptance 09 §9.9 items 1–6, the guard demonstrated, the P0 checks answered, and a completion doc per step carried out. **Met:** the guard (demonstrated), the checks (written, C6 and C11 not checkable here), completion docs for 02–10. **Partly met:** items 1–4 proven on the real lab; 5–6 proven on the real web VM with a stand-in template and, for the kill/resume, only with the in-memory host. **Not met until the code is on the lab Brain VM and the real template runs.**
- **P1 done:** not started (backfill dry run on production data, the bootstrap on one production DNS and one web server, a staff account cut over end to end).

## 9. Verification

One run of the whole Domains folder, the migration guardrail and the config-rules tests, all opt-in lab tests on: **272 tests, 3,897 assertions; 270 passed in that run.** The two that failed were both lab-DNS tests and the cause was a defect in the NEW `LabConnectFlowTest`: it left its line in the shared sites list on the DNS VM, and the next test's first publish (adopt mode, which refuses to drop a line it did not write) failed. The test now releases its claim at the end (which also proves 09 item 9 through the flow) and its tear-down removes its line; the stale line was removed, and the three lab-DNS runs (connect, publish, connect again, in that order) then passed: 3 tests, 71 assertions, and the sites list is unchanged afterwards. The whole folder was **not** run a second time after that fix. Members: `DomainSurfaceControllerTest` + `DomainFlowTest` 17 tests, 79 assertions; `surface.test.ts` 8 tests; type-check of the touched files clean (the project `tsconfig` needs a one-option override with the installed TypeScript, see `complete/08-…`).

## 10. To do next, in order

1\. Put the code on the lab Brain VM; install the worker/scheduler; run the cut-over switch for one lab member and look at the screens in a browser.
2\. Decide the Name.com v4 → CORE v1 move (C7) before anything touches real money.
4\. A copy of production data for the backfill dry run (P1).
5\. Alert delivery (a staff screen or the Brain's notification path) and certificate-expiry tracking.
