# domain_name_management — Planning Index

> **STATUS: PLAN, written 2026-10-07 as part of `domains_deploy`; steps 02–05 and the domain halves of 08–11 built 2026-10-08/09 (see the completion reports in `complete/`). Split out 2026-10-23** into its own folder, together with the shared
> infrastructure the domain work built first. The website-deployment half is in [../new_way/](../new_way/README.md).

> **Written from** a read of `w/members` (`DomainController`, `resources/js/controllers/domains/*`, `core/scripts`), the Brain side in `w/www` (`BrainDnsController`, `BrainHttpServerController`, `Services/Brain/Infra`, `core/scripts`) and `lab2/` (`Vagrantfile`, `provision/dns.sh`, `brain/deploy-tenant.sh`, the seed data).

> **Settings (2026-10-09):** every `DOMAINS_*` name in these plans and reports is now a key under `controller.domains` in the Brain settings document — no `.env`, no defaults. See [complete/00_extras.md E7](complete/00_extras.md).

How a member **adds a domain name** (bought through the platform, or connected), how the platform decides **which claim
on that name is the real one**, how the name is **served from the platform's BIND**, and how every domain gets a **second, platform-owned name**
(`<reference>.sites.appfactory.studio`).

Deploying a website on the name, choosing the server, and moving a live site between servers are a different feature: [../new_way/README.md](../new_way/README.md). A website is bound to one **active claim**, whose table, rules and DNS are defined here.

## Reading order

| Step | File | What it settles |
|---|---|---|
| 00 | [00-overview.md](00-overview.md) | The domain-name goal, the flow on one page, the principles, what is out of scope |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What exists today and what is broken for domain names (F1–F16), plus the shared transport and lab findings (F32–F40) |
| 02 | [02-domain-identity-and-collisions.md](02-domain-identity-and-collisions.md) | **Which claim on a name is active.** Claims, proof of control, precedence, lapse, races — **built 2026-10-08** ([completion report](complete/02-domain-identity-and-collisions.complete.md)) |
| 03 | [03-data-model.md](03-data-model.md) | Tables, columns, the one unique index that makes "only one active" true; publish jobs; site references; events; challenges — **built 2026-10-08** ([completion report](complete/03-data-model.complete.md)) |
| 04 | [04-dns-zone-engine.md](04-dns-zone-engine.md) | Rendering and publishing zones to BIND, the sites name, the subdomains list, system versus member records — **built 2026-10-08** ([completion report](complete/04-dns-zone-engine.complete.md)) |
| 05 | [05-add-and-purchase-flows.md](05-add-and-purchase-flows.md) | The two ways in: purchase (a saga) and connect (a proof). Quota and abuse limits — **built 2026-10-08, Brain side** ([completion report](complete/05-add-and-purchase-flows.complete.md)) |
| 08 | [08-brain-transport-api-and-ui.md](08-brain-transport-api-and-ui.md) | Members to Brain over the signed surface: the **shared channel**, the domain routes, the domains screens — **built 2026-10-09, behind a flag** ([completion report](complete/08-brain-transport-api-and-ui.complete.md)) |
| 09 | [09-lab2-environment.md](09-lab2-environment.md) | lab2: the **production-IP hazard and the guard**, the settings, test names, the customer-DNS harness, the DNS VM — **P0 built 2026-10-09** ([completion report](complete/09-lab2-environment.complete.md)) |
| 10 | [10-rollout-and-testing.md](10-rollout-and-testing.md) | The P0 checks, the claim backfill, the domain test matrix, the per-member **cut-over switch**, the production runbook — **built 2026-10-09; not cut over** ([completion report](complete/10-rollout-and-testing.complete.md)) |
| 11 | [11-decisions-and-open-questions.md](11-decisions-and-open-questions.md) | The domain decisions (D3–D10, D12, D14, D19–D22, D24–D27, D29) with their status board — **updated 2026-10-09: status board, D19–D29** ([completion report](complete/11-decisions-and-open-questions.complete.md)) |
| 13 | [13-turning-it-on.md](13-turning-it-on.md) | **Making the claim system the real one:** the staff dispute actions, one flow (the old add/buy/edit removed), no domain settings — **built 2026-10-24** ([completion](complete/13-turning-it-on.complete.md)); one flow, no domain settings; a run on the dev server is left |
| 14 | [14-wildcard-certificates.md](14-wildcard-certificates.md) | **The Let's Encrypt wildcards and the tenant's HTTPS:** one real Let's Encrypt (no staging), `--domain` for more than one wildcard (sites, storage devices), the HTTPS block with certbot's TLS settings and the HTTP redirect — **built 2026-10-24** ([completion](complete/14-wildcard-certificates.complete.md)); the sites wildcard is issued, sending it, the storage wildcard and a real tenant build are left |
| 15 | [15-registrar-per-domain.md](15-registrar-per-domain.md) | **A domain is handled by the registrar it was bought through**, so the platform can switch registrars without breaking old domains — **built 2026-10-24** ([completion](complete/15-registrar-per-domain.complete.md)); only Name.com exists as a driver |
| 16 | [16-member-dns-records.md](16-member-dns-records.md) | **A member's own DNS records:** a full editor (edit, TTL, a form per type from the server's own rules, 11 types) on the database, the zone rendered with Badcow, and a save from an old view refused — **built 2026-10-24** ([completion](complete/16-member-dns-records.complete.md)); not yet seen in a browser or published to the dev DNS by a member edit |

## Shared infrastructure that lives here

The domain work was built first, so it also holds what both features use. Deployment refers to it; it does not duplicate it.

| What | Where |
|---|---|
| The signed Brain surface and who does what (members vs Brain) | 08 §8.1–§8.2 |
| All domain and website-building **settings in the Brain settings document**, no `.env`, no defaults (`controller.domains`, `DomainSettings`) | D19, `complete/00_extras.md` E7 |
| The Brain logs in to other servers by password through bash + `expect` (`afs-ssh-run`) | D20, `complete/00_extras.md` E8 |
| The production-servers-in-the-lab hazard, `domains:seed-lab`, and the fleet guard (`allowed_server_cidrs`) | 09 §9.1, D26 |
| The per-member cut-over (`new_flow.all` / `new_flow.members`) | 10 §10.6, D22, E9 |
| Queue worker and scheduler on the Brain | D24 |

## Numbering

This folder and [../new_way/](../new_way/README.md) were one plan. **Step, section (§), finding (F), decision (D) and report (E) numbers were kept**, so every reference ("04 §4.5", "F13", "D19", "E7") still means the same thing and nothing had to be renumbered. The consequence is that some step numbers are missing here (06, 07, 12 are website deployment) and some files exist in both folders (00, 01, 03, 08, 09, 10, 11), each holding its own half: a pointer stands where a section moved. A step number alone is enough to find a file; a § number tells you which half.

## Vocabulary

Same as the other plan folders: a **step** is a file; a **phase** is the work inside it, labelled **P0** (needed for the end-to-end flow to work), **P1** (needed before real members use it), **P2** (hardening and nice-to-have). A decision is **D<n>** (step 11); a finding from the audit is **F<n>** (step 01).

- **Domain name** — the registrable name (`example.com`). Exempt from plan quotas (`membership_change/features/1-member_domains_count.md` F1.0); only a **website** counts.
- **Claim** — one member's assertion that they control a domain name. Many claims can exist for one name; **at most one is active**.
- **Active claim** — the single claim whose zone is published and whose website (if any) is served.
- **Sites name** — the platform-owned second name, `<site_ref>.sites.<SITES_ZONE>`. Exists for every active claim.
- **Brain** — `w/www`. **Members** — `w/members`.

## Code tagging and completion documentation

Existing code is tagged `member_domain_creation_process`; new and changed code carries `domains_deploy` and the `AFS_MODIFIER_ADDITION — plans/domains_deploy/<file> §<n>` form, so `grep domains_deploy` finds all of it (the code comments name the file without a folder; the folder is `domain_name_management/` for steps 02–05 and the domain halves of 00, 01, 03, 08–11, `new_way/` for 06, 07, 12 and the website halves).

When a step is carried out, its completion doc goes in `complete/`, named after the plan file (`complete/05-add-and-purchase-flows.complete.md`). Off-plan work goes in `complete/00_extras.md` as dated `E*` sections.
