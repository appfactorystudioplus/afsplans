# 10 — Rollout and testing (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/10-rollout-and-testing.md](../domain_name_management/10-rollout-and-testing.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The website-deployment checks, work order, test cases and the removal of the old pipeline are here; the domain-name ones, and the per-member cut-over switch, are in the other half.

Order of work, the checks that must come first, how existing production data is adopted without breaking live sites, the
test matrix, the cut-over, and rollback.

## 10.1 Working rules for whoever builds this

These are the project's standing rules, restated where they bite this work:

- **Write the chunks, run the test suite once at the end.** Between chunks, static analysis only. One heavy process at a
  time, below-normal priority, kill leftover Node/Electron processes.
- **Do not install SDKs, runtimes or packages to test code.** Verify with stubs and fakes (the fake transport, the fake
  registrar); the owner runs the real install on the lab VMs.
- **Never commit, branch or push** on the assistant's initiative; say which of the independent `w/*` repositories (`www`,
  `members`) each change landed in. **Never start the Vite dev server.**
- **Never assume `php artisan config:cache`.** Read configuration through `config()`; no step depends on it being cached or
  not.
- **New TypeScript** in `members` follows the project TS rules: no optional chaining, no `any`; assert dependencies
  explicitly and throw. `directPayments.create()` stays typed.
- **`.env` files are not edited directly;** the lab keys go in `_env/lab2/.env` and the owner applies them.
- **`wwwafsportal_v1` is not read** (D1 explains why the template contract in 07 §7.3 is stated abstractly).
- **Off-plan fixes** found on the way go in `complete/00_extras.md`, not in a numbered doc.

## 10.2 P0 checks — before writing code that depends on them

Each is a question the plan assumes an answer to. Each gets a one-line result written into the first completion doc.

| # | Check | If the answer is "no" |
|---|---|---|
| C2 | The queue driver in the Brain's `.env` is a real queue (Redis) and a worker can be supervised on the Brain VM | Add the queue + worker provisioner; do not fall back to `sync` |
| C3 | The shared progress client (`afslibs/services/progress`) calls which URL | Make the members proxy answer on it (08 §8.4) |
| C4 | The server-monitoring schema (`plans/brain_flutter/14`) as shipped: table, columns, freshness | Selector runs with `health = unknown` (06 §6.3) |
| C5 | On each tenant host (lab `web`, `brain`): node/npm resolve for non-interactive root SSH, the registry token works, the Composer GitHub token works | Run the "Tenant host dependency access" block of `initial_install.sh` by hand; the `preflight` action keeps checking it |
| C6 | The template satisfies T1–T8 (07 §7.3) | Each gap becomes a template task before step 07 is considered done |
| C8 | Let's Encrypt's current issuance limits (07 §7.6) | Re-check the wildcard-versus-per-site argument |
| C11 | The template tolerates the `releases/<sha>` + `current` layout (07 §7.3 T1); until checked, only Path A's `/home/<user>/websites/www` layout is used | Stay on the existing layout; Path B adapts the template |
| C10 | Which of `members`' `/api/v1/progress/*` routes the widget reads and which only the old script wrote | Remove only the writer at cut-over |

## 10.3 Order of work

```
 W0  P0 checks · lab hazard fix (09 §9.1) · env keys · DNS VM + tenant host changes (09 §9.5-9.7)
 W5  Selector + reservation                                                      (06)
 W6a Path A: the shell-pipeline work list (07 §7.0), server initiation done, LETSENCRYPT_CHECK done  (07)   ← first real site
 W6b Path B: tenant agent (incl. preflight), runner, steps 1-15, website screen                        (07)
 W7b Tenant move (needs W6's agent and runner, W3's publisher, a second lab host) (12)
 W8  P1: reconcile, lapse/promotion, certificates, enable/disable, delete, mail, NameCom sandbox, staff endpoints
 W9  P2 items as wanted
```

W1–W3 can be proved without W4–W6 using artisan commands; W4 puts a UI on them; W5 and W6 need W3 for DNS steps 10–11.
Per the working rules, W1–W7 are written first and the test suite is run once, then W8 starts from a green base.

> **§10.4 (adopting existing production data: the claim backfill, the include-file adopt mode)** is in the domain-name plan: [../domain_name_management/10-rollout-and-testing.md](../domain_name_management/10-rollout-and-testing.md). The tenant backfill that goes with it is in the step 03 completion report of this plan.

## 10.5 Test matrix

Tests live with the code they test (Brain: `w/www/tests`; members: `w/members/tests`; shell agents: scripts run on the lab
VMs). External systems are faked; the lab VMs are the integration environment.

| Area | Level | Cases |
|---|---|---|
| Selector | isolated + concurrency | 06 §6.8 |
| Agent | lab | Each action twice; injected failures; `host_conflict` |
| Pipeline | lab | Happy path; kill at each step ⇒ resume; cancel at each step ⇒ no residue; two-names test; certificate deferral |
| Transport | feature | Unsigned/replayed/other-app-key requests rejected; ownership 404 on another member's claim |
| Guard | isolated | Server outside the allowlist refused before connecting; wrong-`DOMAINS_ENV` boot check |
| UI | manual script + type-check | Each state chip, the verify polling, locked records, the retry/cancel controls, progress to 100 % and the Open buttons |
| Tenant move | lab | The 12 §12.14 matrix: happy path, writes during copy, freeze overrun, kill at every step, cancel at every step, injected mismatch, marker refusal, concurrency, external DNS, rollback, dry run without credentials, legacy route 410 |

## 10.6 Cut-over

A per-member flag `domains.new_flow` (default off) switches the members controller between the old and the new path.

Steps 1–4 (staff first, the production runbook, the backfill, the flag on for new claims and websites) are the domain-name plan's; **the old pipeline goes after them:**

5\. After one clean week: old routes return 410; the old provisioning half of `DomainController`, the `afs-website` wrapper
   build, `commands.sh` provisioning actions, the `update_dns_servers`/`move_zone_and_reload` expect procedures,
   `app_install.sh` provisioning functions, the members copy of the provisioning scripts, and the ungated
   `/api/v1/progress/record` route are removed (07 §7.11). `WEBSITE_DEPLOYMENT_PROCESS.md` is replaced by a pointer to the
   completion docs.
6\. `lab2/brain/deploy-tenant.sh` is retired.
7\. The legacy move chain is retired (12 §12.12) and the tracked `packages/afsserver/tmp/*.json` payload samples are removed from version control; the credentials they contain are rotated by the owner.

## 10.7 Production bootstrap runbook (written when W3/W6 are done; outline)

- **Each HTTP server:** server initiation (07 §7.2): `node`/`npm` on PATH, the npm and GitHub tokens, then `afs-tenant-agent preflight`; install `afs-tenant-agent` and the wildcard certificate for the sites zone; the default catch-all
  vhost; the deploy user and `sudoers` rule; `ping` from the Brain.
- **Brain:** `config/domains.php` values in `.env`; queue worker service; scheduler entries; the pinned template ref; kill switches
  default **off** until the dry runs pass.
- **Dry runs:** `domains:publish --dry-run <claim>` prints the zone and the server commands; the deploy dry run (as
  `InfraTransfer` offers) prints the payload for each step.

## 10.8 Rollback

- Flag off ⇒ the old path serves again; new tables and columns are inert.
- Kill switches pause claims, purchases, deploys, or publishing separately; already-published zones and running sites keep
  serving.
- A bad zone publish never replaces a live zone (stage → check → rename); a bad vhost is restored by the agent.
- The unique indexes and generated columns are harmless if the new code is off.
- Database restore is not needed to roll back; the claim statuses are additive.

## 10.9 Observability

- `brain_domain_events` per claim; `brain_deploy_steps` per deployment; `brain_dns_publish_targets` per server.
- Counters: verification success rate and median time-to-verify; publish latency and failure rate; deploy duration per
  step; failures by step and by server; reservations expired; reconcile drift found.
- Alerts (staff screen and the existing Brain notification path): publish failing > 15 minutes; a deployment `running` with
  no heartbeat for 5 minutes; a server dropping out of eligibility; certificate `not_after` < 14 days; reconcile finding an
  invariant violation.

## 10.10 Cannot be proven from here — a person has to check

3\. Let's Encrypt issuance for a real custom domain, and the wildcard issuance method chosen.
4\. The template's behaviour with two hostnames on one docroot (T2, T6).

## 10.11 Definition of done

- **P0 done:** the lab acceptance in 09 §9.9 items 1–6 pass; the guard is demonstrated; the P0 checks are answered in
  writing; `complete/` docs exist for each step carried out.
- **P1 done:** items 7–9, the backfill dry run against a copy of production data, the production bootstrap on one DNS and one
  HTTP server, a staff account cut over end to end.
- **P2:** as selected.
