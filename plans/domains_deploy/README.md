# domains_deploy — plan folders

The work on domain names and website deployment is three plan folders. They were first one plan (`new_way`); on 2026-10-23 the domain-name part was separated from the website-deployment part so each can be read, built and decided on its own.

| Folder | What it is | Status |
|---|---|---|
| [domain_name_management/](domain_name_management/README.md) | **Domain names**: add (connect or buy) a domain, which claim on a name is the real one, serving the zone from the platform's BIND, the second `<ref>.sites…` name, the registrar purchase saga. Also the shared infrastructure (settings, ssh runner, signed surface, guard, cut-over switch). | Steps 02–05 and the domain halves of 08–11 built 2026-10-08/09; behind the per-member `new_flow` switch, nobody switched over |
| [new_way/](new_way/README.md) | **Website deployment (target design)**: choosing the server, the resumable deploy pipeline with a tenant agent and runner, moving a live site between servers. | Steps 06, 07 and the website halves of 03, 08–11 built 2026-10-08/09 (Path B); step 12 not built |
| [old_way/](old_way/README.md) | **Website deployment as it runs today**: the shell pipeline (`createWebsite` → `commands.sh` → `app_install.sh`), made robust. This is how tenants are deployed now. | Steps 02–05 built 2026-10-22/23, with completion reports in `complete/` |

## Where a topic is

| I want to know about | Read |
|---|---|
| Who owns a name; verification; contested names | domain_name_management 02 |
| What is stored (claims, site references, publish jobs, events) | domain_name_management 03 |
| How a zone reaches BIND; the second name | domain_name_management 04 |
| Buying or connecting a domain | domain_name_management 05 |
| The settings document, the ssh runner, the signed surface, the guard | domain_name_management 08, 09 §9.1, D19, D20, E7, E8 |
| Choosing the HTTP server | new_way 06 |
| The deploy pipeline, the tenant agent, TLS for the sites | new_way 07 |
| Moving a site between servers | new_way 12 |
| Deploying a tenant today; the wildcard certificate; the build scripts | old_way |

## Numbering

`domain_name_management` and `new_way` keep the step, section (§), finding (F), decision (D) and report (E) numbers of the plan they were split from. A reference such as "04 §4.5", "F13" or "D19" is therefore unchanged and is found by its step number; a few step numbers exist in both folders (00, 01, 03, 08, 09, 10, 11), each holding its own half. `old_way` has its own numbers (its findings are **OF<n>**, its decisions **OD<n>**).
