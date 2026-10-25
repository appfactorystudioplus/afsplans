# 11 — Decisions and open questions (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/11-decisions-and-open-questions.md](../domain_name_management/11-decisions-and-open-questions.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. Decisions D1, D2, D11, D13, D15–D18, D23 and D28 are here; the claim, DNS, purchase and shared infrastructure decisions are in the domain-name half.

Everything the owner has to settle, with a recommendation for each. "Needed before" says which step's work depends on the answer, so the rest can proceed.

**Status as of 2026-10-09** (steps 02–10 are built; 12 is not). Each decision below is in one of four states, shown in the board that follows:

- **Owner answered** — the owner said it in words or by instruction.
- **Built as recommended — confirm** — nobody has answered, but the code embodies the recommendation. It stays a decision until the owner says "yes"; if the answer is different, the cost of changing it is stated.
- **Open — needs the owner** — the code does not decide it and something real waits on it.
- **Not reached** — belongs to step 12 (tenant move), not built.

## Status board

| # | State | What the build did | What is left |
|---|---|---|---|
| D1 template | **Owner answered** (by acting) | Deploys pin a commit SHA of the Gitea repository `appfactorystudio/afsportal_v1` (`deploy.template_url`, `deploy.template_ref`, `deploy.template_token` in the Brain settings) | The pin is **empty** in the lab, so no website can be built. T1–T8 and C11 are unchecked (the template is out of scope for this work) |
| D2 scripts | **Open — later** | Path A scripts untouched; the two agents live in `w/www/core/scripts/{dns,tenant,ssh}` | Retire provisioning from `members` after the cut-over (10 §10.6 step 5) |
| D11 server-side website count | **Open — needs the owner** | The `DeployQuota` seam exists (consume at acceptance, release on cancel/delete); the default does nothing | Decide, then plug the Brain's count of live tenants against the plan allowance into the seam |
| D13 per-tenant Gitea repo | **Open — needs the owner** | Path B does not create it; Path A scripts still do | Ask what reads it |
| D15–D17 | **Not reached** | — | Step 12 |
| D18 second lab host | **Open** | The lab seeder sets the Brain VM to take **no** tenants; the web VM is the only tenant host | Set `accepts_tenants = 1` on the Brain VM row when step 12 starts |

## Decisions

| # | Question | Options | Recommendation | Needed before |
|---|---|---|---|---|
| **D1 (answered by the owner's actions; confirm)** | **Which artifact is the website template?** *Answer as built: the template is `packages/wwwafsportal_v1`, produced from `w/www` by `packages/afsserver/bin/copy_portal_source.sh` and held in Gitea as `appfactorystudio/afsportal_v1` (lab: `https://gitea.10-0-0-243.sslip.io`); deploys pin its commit SHA.* Original question: The request names `packages/wwwafsportal_v1`. The deploy code today clones the Gitea `afspackage` repository. `w/AGENTS.md` calls `www` the template member sites are cloned from, and puts `wwwafsportal_v1` permanently out of scope for analysis | (a) the Gitea `afspackage` repo as now; (b) `wwwafsportal_v1`; (c) the `www` app | The owner states it. Whatever it is, pin it by commit SHA (07 §7.3) and run it against T1–T8. If it is `wwwafsportal_v1`, the owner (or a session told to) checks T1–T8 there; this plan could not | 07 (the release builder) |
| **D2** | **Where do the remaining scripts live?** `core/scripts` exists in `members` and in `www`, diverged (F41), and a third copy ships inside every tenant | (a) retire provisioning from `members`, keep one maintained tree in `www`; (b) keep in `members` and have the Brain call it; (c) a shared package | **Now:** keep the scripts where they are and fix them in place (07 §7.0 Path A) — the owner is editing `members/core/scripts` today. **Later:** (a), once Path A is green: the Brain owns the servers, the credentials and the state; the agents (`afs-dns-agent`, `afs-tenant-agent`) are installed on the servers, so the tree that remains is small | 07, 04 |
| **D11** | **Enforce the website count server-side in P0?** (Feature 1's forward edge is advisory, in the browser) | (a) yes, Brain counts live tenants against the plan allowance; (b) wait for feature 1's backward edge | (a) — a refused browser check should not be bypassable by calling the API; cheap because the tenant table is local to the Brain | 07 P0 |
| **D13 (unchanged on Path A)** | **Is the per-tenant Gitea repository needed?** *On Path A the scripts keep creating it (`run_create_repo`, account `appfactorystudio2`); the owner has not asked to drop it.* Original question: `run_create_repo` creates one per tenant, pushes the package into it and clones it back. The audit found no reader at creation; the **old move** does read it (`complete_transfer_tenant` clones it), but step 12 installs from the release artifact instead, so the move no longer needs it | (a) drop it; (b) keep as an asynchronous source backup; (c) required by the builder/editor | Ask what reads it. If nothing, (a). If something does, (b) as a post-deploy job, never inside the critical path | 07 P1 |
| **D15** | **Hold period and freeze ceiling for a move** (and whether the member is emailed) | e.g. 72 h hold / 10 min ceiling; shorter or longer | 72 h and 10 min as defaults in config; email optional. Needs a view on how long a second copy of member data may sit on the old server | 12 P0 |
| **D16** | **Is tenant mail bound to the HTTP server?** Mailboxes and Dovecot config are per host today | (a) yes — a move carries mail and switches the mail host record; (b) mail moves to a platform mail tier first | Decide with the email feature's owner; until then a move of a tenant with mail needs acknowledgement | 12 P1 |
| **D17** | **Moves of domains on external DNS** — the Brain cannot change the member's A record | (a) forwarder until the member updates it, capped at 90 days with notice; (b) refuse moves for such tenants | (a) | 12 P1 |
| **D18** | **Second lab tenant host for move tests** | (a) the `brain` VM as host (P0); (b) a new `web2` VM at `10.0.0.245` | (a) now, (b) when moves are in regular use | 12 P0 / P1 |
| **D23** | **Who pins the template and when?** Until `deploy.template_ref` is set no website can be built; the lab web VM also lacks node/npm | The owner runs the server-initiation block and sets the pin; or a session is told to | The owner. Pin a commit that has passed T1–T8 | 07 real run |
| **D28** | **When do the old routes go?** 10 §10.6 step 5 removes the old provisioning half of `DomainController`, the `afs-website` wrapper, the expect procedures and `/api/v1/progress/record` after one clean week for everyone | A date after the last member is switched | The owner, after D22 reaches "everyone" | 10 §10.6 |

## Settled in this plan (recorded so they are not re-opened by accident)

- **One website per active claim**, keyed by the claim's reference, not the name — feature 1, D6.
- **One docroot and one vhost serve both names; neither redirects** — 07 §7.5.
- **Zones, includes and vhosts are rendered in full, written atomically, verified** — 00 §0.3.
- **Progress is additive** (`step = 1` per call, `max_steps` = number of calls, final `complete` = 100%) — 07 §7.7, 01 F29.
- **Delivery is two paths:** fix the shell pipeline in place first, build the agent and runner after — 07 §7.0.
- **Certbot is controlled by `LETSENCRYPT_CHECK`** (`false` on the Vagrant labs and local) — 07 §7.6.
- **Tenants install `vendor/` and `node_modules/` on the host; credentials are set once at server initiation** — 07 §7.2–7.3.
- **A tenant is `active` once its site is healthy on the host and published — step 10 of the pipeline, not 15** — the sites entry and the apex follow a claim's server only while its tenant is active; a cancel puts it back. Built 2026-10-09 (07 report §3).
- **Deployments make progress with no queue worker:** `domains:deploys` (every minute) runs or resumes anything without a runner; a queue worker only makes it immediate — 07 report §1.

## Open questions that are checks, not decisions

The P0 checks C1–C11 of 10 §10.2 were answered on 2026-10-09 (`complete/10-rollout-and-testing.complete.md` §2). Two could not be checked here: **C6** and **C11** (the template's contract and layout; the template is out of scope for this work). The others are the domain-name plan's, except C2 (the queue), which is shared.

## What the owner should read first

1. **D23** — what stands between the built code and a real website: the template pin and node on the web VM.
2. **D11** — the product decision with nothing built behind it: the website allowance.
3. **D24** (shared, in the domain-name plan) — the scheduler and worker; `domains:deploys` makes progress without a worker, but the scheduler is required for everything scheduled.
