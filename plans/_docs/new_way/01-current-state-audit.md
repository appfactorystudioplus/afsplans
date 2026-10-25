# 01 — Current-state audit (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/01-current-state-audit.md](../domain_name_management/01-current-state-audit.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. Findings F17–F31, F37 and F41 (server choice, the deploy itself, the stale process document, the diverged script trees) are here; F1–F16 and F32–F40 are in the domain-name audit.

Read from the code on 2026-10-07; nothing was run against a live server. Where a finding is an inference from code rather
than something observed, it says so. Paths are relative to `w/` unless noted.

## 1.1 What exists, in the order a request travels

```
members UI (resources/js/controllers/domains/*.ts)
   search.ts / purchase.ts / dns.ts / website.ts / api.ts / initDomains.ts
        │  fetchWithSessionRefresh  →  /api/v1/domains/*
        ▼
members/app/Http/Controllers/Brain/DomainController.php        (1337 lines)
   index store update destroy listWebsites search purchase createWebsite
   (createWebsite2 is not routed; getTenantData builds the whole tenant spec)
        │  Http::get/post  (NO credentials)  →  BRAIN_API_URL = /portal/api/v1/brain/*
        ▼
www/app/Http/Controllers/Brain/BrainDnsController.php          zones, servers, named, subdomains, reload* (stubs)
www/app/Http/Controllers/Brain/BrainHttpServerController.php   servers, tenants, getAvailableServer, moveTenants
        ▼ (separately, from members)
members/core/scripts/background_runner.php  --provision_new_website1
   → bin/afs-website (setuid wrapper) → commands.sh --create-domain-on-server
       step1 zip builder · step2 DNS (expect: update_dns_servers → app_install.sh build_dns)
       step3 expect: create_domain_on_remote_server → scp builder → app_install.sh build_all
```

Tables: `brain_dns_zones`, `brain_dns_subdomains`, `brain_dns_named`, `brain_dns_servers`, `brain_http_servers`,
`brain_http_server_tenants`, `brain_http_servers_regions`, `brain_server_clusters` (all created in
`www/database/migrations/2026_05_15_000001_create_brain_tables.php`), and in the shared database `members_websites`
(model `members/app/Models/Brain/Website.php`). The `json` columns are TEXT encoded with `Utils_encodeJson`.

## 1.2 Findings

Severity: **A** = the requested behaviour cannot work or is wrong; **B** = works until it meets a second member / second
server / a failure; **C** = cleanup.

### Choosing a server

| # | Sev | Finding | Where |
|---|---|---|---|
| F17 | A | **"Best server" is "first active server of type Head."** `selectTenantServer` returns the first match in table order. The least-tenants query (`getAvailableServer`) is only used by the unrouted `createWebsite2`, and it ignores `server_type`. | `DomainController.php:1169-1177`; `BrainHttpServerController.php:96-145` |
| F18 | B | The geo-distance code (MaxMind + Google Distance Matrix, `sleep(3)` per server) exists only as `…1`-suffixed dead functions. Capacity (`tenant_size`, `ram_size`) is read only by those dead functions. | `background_runner.php:372-628` |
| F19 | B | Two concurrent deploys can both pick the last slot — there is no reservation. | — |

### Deploying the website

| # | Sev | Finding | Where |
|---|---|---|---|
| F20 | A | **Paths are production-shaped and hard-coded:** `/mnt/node1/appfactorystudio/tmp`, `/home/appfactorystudio/websites/{members,dev/members15}`, `$filepath` chosen by whether the URL contains `members15`. The `members15`/`www15` switch is a string compare. | `DomainController.php:465-509` (`mkdir` at 507); `background_runner.php:89-109` |
| F21 | A | **The job is started with `popen("nohup php … &")` inside a web request**, through a setuid C++ wrapper (`bin/afs-website`, compiled on each tenant by `g++`). No queue, no retry, no state except the progress rows, no way to resume. | `DomainController.php:554-557`; `core/scripts/wrapper.c` |
| F22 | A | **Every tenant gets the same database password, and the generated one is ignored.** `getTenantData` hard-codes `db_password`; `run_create_repo` hard-codes `new_db_pass` and creates the MySQL user with it. The per-tenant `d_…` value in the old path is never used. Same for `Username` (a literal). | `DomainController.php:1268-1271`; `app_install.sh:527` (`run_create_repo`) |
| F23 | B | **Two copies of the site per tenant** (`www` and `www-own`), each a full `composer install`, `npm install`, `npm run build`, `migrate`, `db:seed`. Vhost 1 (`{tenant_subdomain}.{tenant_domain}`) → `www`; vhost 2 (sites name) → `www-own`. They share one database and have different `APP_URL`s. Disk and build time ×2, and the two drift. | `app_install.sh` `run_create_repo` |
| F24 | B | **Hostname inconsistency (inferred, not run):** the tenant's `Address` is `https://www.<domain>`, but the vhost generated is `{tenant_subdomain}.{tenant_domain}` where `tenant_subdomain` is the *managed* subdomain (`www15` in lab2's `.env`, `SUBDOMAIN_PROPERTIES_SUBDOMAIN`). The site would answer on `www15.example.com`, not `www.example.com`. | `DomainController.php:1252,1264`; `app_install.sh` printf blocks |
| F25 | B | **Build on every deploy:** clone the package repo, create a per-tenant Gitea repo, push, clone, `composer install`, `npm install`, `npm run build` — on the tenant host, per tenant. Deploy time is dominated by it. | `app_install.sh` |
| F26 | B | **No idempotency or rollback.** `useradd --badname`, `mkdir`, `CREATE DATABASE` guarded; the repo creation, `cp -rf`, `rm -rf …/.git`, the `echo >>` are not. A failure at step N leaves N-1 steps of debris and the retry path is "deploy again". | `app_install.sh` |
| F27 | B | **Secrets travel with the job.** `transfer_data` includes `mysql_password`, `brain_pass`, `portal_root_password`, the Gitea tokens and the first Brain-type server's login, and the JSON is written to a world-traversable temp path then `scp`'d to the target host. The job needs one server's credential, not the Brain's. | `DomainController.php:471-509` |
| F28 | B | **Progress is reported through an intentionally ungated endpoint** (`POST /api/v1/progress/record`, no Authorization) because a remote shell script has no bearer token. Anyone who learns a `tracker_key` can write to its progress. | `routes/web.php:436+`; `app_install.sh:147` |
| F29 | C | **Progress is additive by design, and fragile.** `ProgressService` adds each reported `step` to a running total and the job is done when the total reaches `max_steps`; every script reports `1`. That is the owner's intended model (decided 2026-10-08, after an earlier draft of this audit called it a defect and a change to it was reverted). It works because a normal run makes exactly 19 calls (1 `createWebsite`, 5 `commands.sh`, 1 `build_dns`, 12 `app_install.sh`) and `createWebsite` initialises `max_steps = 19`. The fragility is the coupling: adding or removing a `record_progress` call, or a retry that posts twice, shifts the percent until the final `complete` forces 100. | `commands.sh`, `app_install.sh`, `ProgressService::calculateProgress` |
| F30 | B | **Completion never reaches the UI state.** `onComplete` in `website.ts` has its body commented out, so `has_website` / `site_enabled` are not set after a successful deploy; they only change by the member toggling. `has_website` is written into the zone JSON, a third place that says "this domain has a website". | `website.ts` |
| F31 | B | **Delete website is a flag flip** on the zone JSON; tenant, vhost, DB, user, DNS records remain (feature 1 F1.3 says the same). | `website.ts` |

### Brain transport and purchase

| # | Sev | Finding | Where |
|---|---|---|---|
| F37 | C | `WEBSITE_DEPLOYMENT_PROCESS.md` documents a `core/scripts2` directory, Redis keys and a `DomainsController.ts` that no longer exist in that shape. It is stale; this plan supersedes it. | `members/WEBSITE_DEPLOYMENT_PROCESS.md` |

> **F32, F33 (the open Brain routes and the whole config going to members) and F34–F36 (the purchase) are in the domain-name audit:** [../domain_name_management/01-current-state-audit.md](../domain_name_management/01-current-state-audit.md). F32 and F33 also affect the website routes.

### lab2

| # | Sev | Finding | Where |
|---|---|---|---|
| F41 | C | The same `core/scripts` tree exists, **diverged**, in `members` and `www` (the Brain has `--transfer-tenants`, members has `--provision_new_website1`). Every tenant is a clone of the template and receives a third copy. | `members/core/scripts`, `www/core/scripts` |

> **F38 (the lab seed holds production servers — it affects DNS servers and web servers alike), F39 and F40 are in the domain-name audit:** [../domain_name_management/01-current-state-audit.md](../domain_name_management/01-current-state-audit.md).

## 1.3 What is worth keeping

- **The Brain table family** (`brain_dns_zones`, `_subdomains`, `_servers`, `brain_http_servers`, `_tenants`) — extend, do not replace.
- **`ProgressService` and the progress widget** — keep the UI contract; change what feeds it.
- **`InfraTransfer`'s discipline** (row locks, dry-run payload, `client_key` idempotency, audit row, enable flag) — the
  deploy runner should copy these properties.
- **Lab2's tenant-host shape** (nginx :443 → Apache :808 → php-fpm, tenant = Linux user under `/home/<name>/websites/…`,
  wildcard cert for the sites zone, SSH key trust from `brain` to `web` and `dns`). This is also production's shape.
- **`record_progress`'s idea** (a remote script reporting steps) — keep the idea, replace the unauthenticated channel.
