# 00 — Overview

## 0.1 Goal

The old way keeps working, on the lab and in production, and fails loudly instead of quietly. A member presses *Create website*; members builds the job JSON (`getTenantData`) from Brain data; the shell pipeline builds the tenant on a tenant host exactly as today. This plan changes how the data is fetched, adds checks and cleanups around the same scripts, and adds a wildcard certificate for the sites names.

## 0.2 The decision (owner, 2026-10-09) and what it rests on

- The old way **has been used and works**. The new way (the Brain's runner and tenant agent, `../new_way/`) was set aside because it left out things the tenant needs: the `subdomain_properties` rows that only the build can write from the member's data, the `www` / `www-own` pair (a single docroot with `SetEnv APP_URL` did not import assets properly), certbot, and it had never built the real template.
- **The tenant gets its membership data from the JSON object that `getTenantData` builds** from Brain data. That object is the contract and does not change.
- Do not deviate more than needed. The first draft of this plan did; see [06-set-aside.md](06-set-aside.md).

## 0.3 The old flow on one page

```
members browser  #btn_create_website
   └─ POST /api/v1/domains/create-website                       DomainController::createWebsite()        w/members/app/Http/Controllers/Brain/DomainController.php:395
        ├─ getTenantData()  ── 8 calls to the Brain ──────────▶ /dns/zones /config /dns/servers /http/servers /dns/subdomains /http/regions /subdomain_properties   (:1002–1052)   ← STEP 02 CHANGES THESE
        │     builds: tenant (the 21 properties' source), website, git_settings, selected_server, zones, dns_servers, subdomain_properties   (:1059–1160)
        ├─ writes the job file /mnt/node1/appfactorystudio/tmp/main_<rand>/vm_<rand>.json                (:507–511)
        ├─ creates the members_websites row, POST Brain /http/tenants                                      (:515–549)   ← STEP 02 (the POST)
        └─ popen: php core/scripts/background_runner.php --provision_new_website1 <dir> <folder> <file>    (:555–559)
             └─ copies remote/builder to the job folder; RunCommandBG1 → bin/afs-website "commands.sh --create-domain-on-server …"   background_runner.php:78–110
                  └─ commands.sh   step1 zip builder · step2 DNS servers (expect) · step3 create_domain_on_remote_server (expect → ssh root@tenant host)   commands.sh:435–505
                       └─ on the tenant host: app_install.sh build_all → make_storage_directories · run_create_repo (:375)
                            clone template · create the tenant's Gitea repo and push · useradd · copy to /home/<u>/websites/www · composer install · database + user ·
                            .env values · migrate · seed · npm install · npm run build · INSERT subdomain_properties (:596) · copy www → www-own · vhosts ·
                            APP_URL / ASSET_URL / BRAIN_API_URL · certbot (sites name) · write_dovecot_files · record_progress "complete"
   browser polls GET /api/v1/domains/website-progress/{tracker_key}
   (progress is additive: createWebsite sets max_steps = 19, every record_progress call reports step = 1, POST /api/v1/progress/record is ungated by design)
```

## 0.4 Principles

1. **Same flow, same JSON, same scripts.** A change must be traceable to a finding in step 01 and be the smallest that fixes it.
2. **No literals for what differs between environments** (Brain/Checkpoint hosts, the tenant host's paths and passwords). They come from the Brain data in the JSON or from the app's `_env/<mode>/.env`.
3. **Lab and production run the same code.** The difference is configuration only: `LETSENCRYPT_CHECK`, the certificate files, the host URLs.
4. **A step that fails says so.** Today the script ends with `complete` whatever happened.
5. **No secret goes to the browser, and none stays in a log or a world-readable file** in production.
6. **Verify on the lab before claiming**; say plainly what only production can prove.

## 0.5 Out of scope

The new way's runner and agent; moving tenants between servers; buying domains; mail beyond what `write_dovecot_files` does; ending the `www` / `www-own` pair; everything in [06-set-aside.md](06-set-aside.md).
