# domains_deploy / new_way — Website deployment — Planning Index

> **STATUS: PLAN, written 2026-10-07; steps 06 and 07 and the website halves of 03 and 08–11 built 2026-10-08/09; step 12 not built (see `complete/`). Narrowed 2026-10-23:** the domain-name plans (claims, DNS, purchase) were split out into
> [../domain_name_management/](../domain_name_management/README.md). This folder is now the **website deployment** plan only.

> **Written from** a read of `w/members` (`DomainController`, `resources/js/controllers/domains/*`, `core/scripts`), the Brain side in `w/www` (`BrainDnsController`, `BrainHttpServerController`, `Services/Brain/Infra`, `core/scripts`) and `lab2/` (`Vagrantfile`, `provision/dns.sh`, `brain/deploy-tenant.sh`, the seed data).

How a member **deploys a website** on a domain they hold: the system picks the best HTTP server, builds the site there as a resumable pipeline, and the site answers on **both** names (the member's name and the platform's `<site_ref>.sites…` name); and how a live site is **moved** between servers.

It depends on the domain-name plan for what a website is bound to: an **active claim**, its zone and its sites name ([../domain_name_management/README.md](../domain_name_management/README.md)). It does not depend on how the claim was made.

> **Which way deploys tenants today.** The tenant websites are currently deployed by the **old way** (`members` `DomainController::createWebsite` → `commands.sh` → `app_install.sh`), planned and hardened in [../old_way/](../old_way/README.md). This folder is the **target design** that replaces the shell pipeline with a tenant agent and a queued runner; step 07 §7.0 describes the two delivery paths.

## Reading order

| Step | File | What it settles |
|---|---|---|
| 00 | [00-overview.md](00-overview.md) | The website-deployment goal, the flow on one page, the principles, the delivery order, what is out of scope |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What exists today and what is broken for choosing a server and deploying (F17–F31, F37, F41) |
| 03 | [03-data-model.md](03-data-model.md) | The tenant, deployment and step tables, the typed server columns, `members_websites` — **built 2026-10-08** ([completion report](complete/03-data-model.complete.md)) |
| 06 | [06-server-selection.md](06-server-selection.md) | How the "best" HTTP server is chosen, reserved and explained — **built 2026-10-08** ([completion report](complete/06-server-selection.complete.md)) |
| 07 | [07-website-deployment-pipeline.md](07-website-deployment-pipeline.md) | The deploy as a resumable pipeline: pinned template ref, on-host dependency install, tenant agent, one vhost, two names, TLS — **Path B built 2026-10-09** ([completion report](complete/07-website-deployment-pipeline.complete.md)) |
| 08 | [08-brain-transport-api-and-ui.md](08-brain-transport-api-and-ui.md) | The website routes and the website screen (the channel itself is the domain-name plan's) — **built 2026-10-09, behind a flag** ([completion report](complete/08-brain-transport-api-and-ui.complete.md)) |
| 09 | [09-lab2-environment.md](09-lab2-environment.md) | lab2: the tenant host, the website worker, the website acceptance items, the interim lab tooling — **P0 built 2026-10-09** ([completion report](complete/09-lab2-environment.complete.md)) |
| 10 | [10-rollout-and-testing.md](10-rollout-and-testing.md) | The website checks, the work order, the website test matrix, retiring the old pipeline, the runbook for HTTP servers — **built 2026-10-09; not cut over** ([completion report](complete/10-rollout-and-testing.complete.md)) |
| 11 | [11-decisions-and-open-questions.md](11-decisions-and-open-questions.md) | The website decisions (D1, D2, D11, D13, D15–D18, D23, D28) with their status board — **updated 2026-10-09: status board, D19–D29** ([completion report](complete/11-decisions-and-open-questions.complete.md)) |
| 12 | [12-tenant-move.md](12-tenant-move.md) | Moving a live site between servers: freeze, verified copy, DNS cut-over, hold, rollback |

## Delivery order (read this first)

The target design replaces the shell pipeline with a tenant agent and a queued runner. The shell pipeline is what is being fixed and run on the lab, so it is delivered in two paths: **Path A** fixes `members/core/scripts` in place ([07 §7.0](07-website-deployment-pipeline.md)), **Path B** builds the agent and runner afterwards. Step 06 (selection) and step 12 (moves) do not depend on the choice. What has actually been built so far is in [complete/00_extras.md](complete/00_extras.md); the lab tooling is described in [09 §9.11](09-lab2-environment.md).

## What is in the domain-name plan

| Needed here | Where it is |
|---|---|
| Claims, proof of control, the single active claim per name | [../domain_name_management/02-domain-identity-and-collisions.md](../domain_name_management/02-domain-identity-and-collisions.md) |
| The zone, the sites name, DNS publishing | [../domain_name_management/04-dns-zone-engine.md](../domain_name_management/04-dns-zone-engine.md) |
| The signed Brain surface (who does what), error codes | [../domain_name_management/08-brain-transport-api-and-ui.md](../domain_name_management/08-brain-transport-api-and-ui.md) |
| All settings in the Brain settings document (`controller.domains`, including `deploy.*`, `tenant_agent.*`) | D19 and `complete/00_extras.md` E7 in the domain-name plan |
| The ssh runner (`afs-ssh-run`) the tenant agent transport uses | D20 and E8 in the domain-name plan |
| The production-servers-in-the-lab hazard and the fleet guard | [../domain_name_management/09-lab2-environment.md](../domain_name_management/09-lab2-environment.md) §9.1 |
| The per-member cut-over switch | [../domain_name_management/10-rollout-and-testing.md](../domain_name_management/10-rollout-and-testing.md) §10.6 |

## Numbering

Step, section (§), finding (F), decision (D) and report (E) numbers are the ones from the combined plan (see the domain-name README), so references from code and other plans still hold. Files 00, 01, 03, 08, 09, 10 and 11 exist in both folders, each with its own half; a pointer stands where a section moved. Steps 02, 04 and 05 are domain-name steps and are not in this folder.

## Vocabulary

A **step** is a file; a **phase** is the work inside it, **P0** (needed for the end-to-end flow to work), **P1** (needed before real members use it), **P2** (hardening and nice-to-have). A decision is **D<n>** (step 11); a finding is **F<n>** (step 01).

- **Website** — a deployed tenant on an HTTP server, bound to exactly one active claim (decided in feature 1, D6).
- **Tenant** — the website's server-side record (`brain_http_server_tenants`): user, database, directories, vhost.
- **Sites name** — the platform-owned second name, `<site_ref>.sites.<SITES_ZONE>` (defined in the domain-name plan).
- **Brain** — `w/www`. **Members** — `w/members`.

## Code tagging and completion documentation

New and changed code carries `domains_deploy` and `AFS_MODIFIER_ADDITION — plans/domains_deploy/<file> §<n>`; existing code is tagged `member_domain_creation_process`. Completion docs go in `complete/`, named after the plan file; off-plan work goes in `complete/00_extras.md` as dated `E*` sections.

## One thing to know before reading

The request names `packages/wwwafsportal_v1` as the website template. The standing project rule (`w/AGENTS.md` §1) excludes that folder from analysis, so **this plan does not read it** and treats the template as an opaque artifact described by a contract (step 07 §7.3). What the code actually clones today is the Gitea `afspackage` repository (`app_install.sh`, `git clone … $GITEA_PACKAGE_REPO`). Which one is the template is **D1**.
