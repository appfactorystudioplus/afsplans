# domains_deploy / old_way — Planning Index

> **STATUS 2026-10-22: steps 02 to 07 have completion records in [`complete/`](complete/). Code is written and tested locally; nothing has been run as a real deploy on dev; each completion record says what is verified and what is not.** Most of 02 and 03 was built earlier under `create_member_redo/11` and `/12`; the records say which.
>
> *Original status: PLAN, 2026-10-09 (second version, narrowed the same day). Nothing here is built.* Written after an audit of `w/members` (`DomainController`, `core/scripts`), `w/www` (Brain routes and signed surface), `w/auth` (Checkpoint CORS),
> `packages/wwwafsportal_v2` (the template) and read-only checks of the live lab (`lab2`: Brain VM `10.0.0.243`, web VM `10.0.0.244`).

> **Folder layout (2026-10-23).** `domains_deploy/` now holds three plans: this one (`old_way`, how tenants are deployed today), [`../new_way/`](../new_way/README.md) (the target website-deployment design) and [`../domain_name_management/`](../domain_name_management/README.md) (domain names: claims, DNS, purchase, and the shared settings/ssh/transport infrastructure). See [`../README.md`](../README.md).

## What this folder is

The owner chose the **old way** for deploying tenant websites: `DomainController::createWebsite` → `background_runner.php` → `commands.sh` → `commands.expect` → `remote/builder/app_install.sh`.
It **has been used and works**. This plan does not redesign it. It does three things:

1. makes it run against today's code and the lab (the Brain now refuses its unauthenticated calls),
2. makes it **more robust** (checks, re-runnable steps, no secrets in the browser response or in logs, no literals),
3. adds what is missing for production (a wildcard certificate for the sites names).

**The contract that does not change:** `DomainController::getTenantData()` builds the one JSON object the scripts read, from data the Brain supplies. Its shape, the scripts that read it, the `www` / `www-own` pair, the per-tenant Gitea repository, the shell + `expect` transport, the additive progress model and the 21 `subdomain_properties` rows inserted at build time all stay.

An earlier draft of this folder proposed much more (claim-gated entry, new identifiers, the Brain as the only DNS writer, a new lifecycle). The owner rejected that as the same mistake the new way made. It is kept, not lost, in [06-set-aside.md](06-set-aside.md) with the condition under which each item would come back.

## Reading order

| Step | File | What it settles | Phase |
|---|---|---|---|
| 00 | [00-overview.md](00-overview.md) | The decision, the old flow on one page, principles, what stays | — |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What is broken or risky today, with file, line and how it was checked (**OF1–OF17**) | — |
| 02 | [02-getting-the-data-from-the-brain.md](02-getting-the-data-from-the-brain.md) | The one change to `getTenantData`: how it reaches the Brain now that the Brain asks for a login | P0 |
| 03 | [03-robustness.md](03-robustness.md) | Checks, re-runnable steps, the browser response, secrets, the value defects in the JSON and `subdomain_properties` | P0/P1 |
| 04 | [04-lab-and-template.md](04-lab-and-template.md) | What the lab needs, the template refresh, environment values, the acceptance run | P0 |
| 05 | [05-certificates.md](05-certificates.md) | `LETSENCRYPT_CHECK`, the wildcard for `*.sites.<zone>`, the member's own domain | P0/P1 |
| 06 | [06-set-aside.md](06-set-aside.md) | What the first draft proposed and the owner set aside | — |
| 07 | [07-decisions-and-open-questions.md](07-decisions-and-open-questions.md) | What the owner decides (**OD1–OD7**) | — |

## Vocabulary

A **step** is a file; a **phase** is the work inside it, **P0** (needed for one real deploy on the lab), **P1** (before real members use it in production), **P2** (hardening).
Findings are **OF<n>** (step 01), decisions **OD<n>** (step 07). These are this folder's own numbers and are not the F and D numbers of `new_way` and `domain_name_management` (those two share one numbering); `new_way D13` means that folder's.
Terms: **Brain** = `w/www`; **members** = `w/members`; **template** = `packages/wwwafsportal_v2`, held in Gitea as `appfactorystudio/afsportal_v1`; **sites name** = `<ref>.sites.<zone>`.

## Code tagging and completion documents

New and changed code carries `domains_deploy_old_way` and `AFS_MODIFIER_ADDITION — plans/domains_deploy/old_way/<file> §<n>` so `grep domains_deploy_old_way` finds it; existing old-flow code is tagged `member_domain_creation_process`.
A completed step gets `complete/<NN>-<same name as the plan>.complete.md`; off-plan fixes go in `complete/00_extras.md` as dated `E*` sections.
