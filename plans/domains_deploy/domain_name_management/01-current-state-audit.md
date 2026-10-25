# 01 — Current-state audit (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/01-current-state-audit.md](../new_way/01-current-state-audit.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. Findings F1–F16 and F32–F40 (the domain findings and the shared transport and lab findings) are here; F17–F31, F37 and F41 are in the website-deployment audit.

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

### Ownership and collisions

| # | Sev | Finding | Where |
|---|---|---|---|
| F1 | A | **No global ownership.** `store()` asks the Brain for zones named `domain_name` *and filtered by the calling member's reference*, so another member's zone for the same name is invisible to it. A second member adds `example.com` freely. | `DomainController.php:174-201` |
| F2 | A | **The Brain does not enforce it either.** `saveZone` does `BrainDnsZone::firstOrNew(['domain_name', 'member_reference_num'])`; `brain_dns_zones` has no unique index at all. Two members ⇒ two rows ⇒ two competing "zones" for one name. | `BrainDnsController.php:195`; migration `:63-86` |
| F3 | A | **`own` means three things.** The UI badge reads `own == 1` as "Managed", the server reads it as "purchased through us" (`$provider = registrar` only when `own`), and the duplicate check treats `own == 0` rows as overridable by an `own == 1` add. A connected domain can be silently replaced by a purchased one with the same name, with no ownership test. | `initDomains.ts` badge; `DomainController.php:184-208` |
| F4 | B | **Tenants are not unique either.** `assignTenant` has no unique key; feature 1's audit already found one domain name with three tenant rows in lab2. | `BrainHttpServerController.php:243-275`; `features/1-member_domains_count.md` F1.3 |
| F5 | B | **`destroy()` is a soft flag** (`active = 0` on the zone). Nothing removes the zone from BIND, the vhost, or the sites record, and a second `store()` for the same name by the same member hits the same row. | `DomainController.php:241-256` |

### Adding a domain does not touch BIND

| # | Sev | Finding | Where |
|---|---|---|---|
| F6 | A | **`store()` only saves a Brain row** (`json_zone_data` = default SOA + one MX). No zone file is rendered, nothing is pushed, nothing reloaded. The user-visible DNS tab edits `json_zone_data` and `PATCH`es it back; it still never reaches a name server. | `DomainController.php:210-222`; `dns.ts` `saveZoneData` |
| F7 | A | **The reload endpoints are stubs** returning `"… queued."` and doing nothing. `InfraDnsRules` says outright: it "does NOT regenerate `json_zone_files` or push anything to a name server: the app has no such route yet". | `BrainDnsController.php:407-445`; `Services/Brain/Infra/InfraDnsRules.php` header |
| F8 | A | **The only code that ever wrote a member zone to BIND is the website pipeline**, and only as a side effect, and it **appends** one line to `appfactory.studio.include.zone` (`echo "$systemuser IN A $serverip" >> …`) then `systemctl reload named`. No duplicate check, no `named-checkzone`, no serial bump, no removal. Run twice, you get two lines. | `app_install.sh:795-817` (`build_dns`) |
| F9 | B | **The member's own zone is never created on a DNS server in the current pipeline.** `step2` runs `update_dns_servers`, which `scp`s the *whole builder directory* to the DNS server and runs `build_dns`, which only touches the sites include. The member-zone push (`update_named_config`, `move_zone_and_reload`) belongs to the older `--manage-update-member-dns-records` path that `createWebsite()` no longer calls. | `commands.sh` `step2`; `commands.expect:373-453` |
| F10 | B | **The SOA serial never moves in the UI.** `createZoneData` and `createDefaultMemberZone` fix it at the creation date; only the Brain staff path (`InfraDnsRules::nextSerial`) bumps it. Secondaries would ignore every edit. | `dns.ts`; `DomainController.php:1132-1161`; `InfraDnsRules.php` |
| F11 | B | **Default zone is not self-consistent.** MX points at `server1.<domain>.`, which has no A record in the generated zone. Also no NS records (the `nameservers` array is empty by default). | `DomainController.php:1132-1161` |
| F12 | B | **One flat `records` array** mixes records the platform must own (apex, `www`) with records the member typed. A server move could not update the former without clobbering the latter. | `dns.ts`; zone JSON shape |

### The second name (`<ref>.sites.…`)

| # | Sev | Finding | Where |
|---|---|---|---|
| F13 | B | **It is created at deploy time, not domain time.** `getTenantData` mints `x1s` + 12 random characters and uses it as *system user, DB user, DB name prefix, sites subdomain, and Subdomain2* — one string, five jobs. | `DomainController.php:1231`, `1262-1283` |
| F14 | B | **The "subdomains list" write is the append in F8**, plus an unused `brain_dns_subdomains` row in the old path. Two sources of truth, neither rendered. | `app_install.sh`; `background_runner.php:111-325` (dead) |
| F15 | C | **The UI placeholder is wrong:** `xxx.site.appfactory.studio` (singular), written into the zone JSON as `alternate_domain_name` for every domain whether or not a site exists. `btn_open_site` / `btn_open_alternate` have `window.open` commented out. | `website.ts` |
| F16 | C | The sites zone name is **hard-coded** (`sites.appfactory.studio`, `.test2` only in lab2's own script). | `app_install.sh`; `deploy-tenant.sh` |

### Brain transport and purchase

| # | Sev | Finding | Where |
|---|---|---|---|
| F32 | A | **Members calls the Brain unauthenticated** (`Http::get($brainApiUrl().'/dns/zones')` and `/config`, `/http/*`) while those routes are meant to need a portal login. Feature 1 records this as its open **D5**. The Brain-side parts of every flow here are suspect until it is settled. A signed, per-app surface exists (`brain.surface` middleware, `BrainSurfaceClient`) and is used for billing enforcement; it is the right channel. | `DomainController.php` passim; `plans/TODOS.md` "portal.level99"; `www/routes/api.php:27-45` |
| F33 | B | **`GET /config` returns the Brain's entire config to members** (registrar keys, Gitea tokens, root passwords, SSH logins) to read two or three fields. | `DomainController.php:917-927, 1179-1215` |
| F34 | A | **Purchase is orchestrated by the browser:** charge (direct payments) → `POST /purchase` (registrar) → `POST /` (record the zone, `own=1`). Close the tab after the charge and the member paid with no domain recorded; registrar failure after the charge has no refund path in this code. | `purchase.ts:190-235` |
| F35 | B | **Registrar calls are hard-wired to `https://api.dev.name.com`** for both "development" and "production" credentials; the environment is picked by comparing the request host to `members15.appfactory.studio`. In lab2 the host is `members.10-0-0-243.sslip.io`, so it selects *production* credentials and calls the *dev* API. | `DomainController.php:787, 801, 858, 874, 897` |
| F36 | B | The purchase trusts the client's `purchasePrice` (passed on to the registrar) and nothing reconciles it with the invoice amount charged. | `DomainController.php:856-880` |

### lab2

| # | Sev | Finding | Where |
|---|---|---|---|
| F38 | **A** | **The lab database seed contains production servers.** `brain_dns_servers` has an **active** row for `45.79.42.137` (root), and three active `brain_http_servers` rows (`45.79.42.137` type `Brain`; `3.130.107.19` `node1.appfactory.studio` and `3.138.213.208`, both `Head`). `createWebsite()` takes the first active `Head` (`node1.appfactory.studio`) and SSHes to it. Running "create website" in lab2 as it stands would act on a production host. | `lab2/databases/data-only/v2/testdb_appfactorystudio.data-only.sql` lines 37-52 |
| F39 | B | lab2 BIND serves one zone, `test2`; it has no member-zone include, no `rndc` use, and `provision/dns.sh` overwrites live tenant records when re-run. The lab's own tenant tool (`brain/deploy-tenant.sh`) is a separate, simpler mechanism that does not share code with the app's pipeline. | `lab2/provision/dns.sh`; `lab2/brain/deploy-tenant.sh` |
| F40 | B | The Windows resolver rule (`Manage-LabDns.ps1`) routes only `.test2` to the lab DNS, so a member domain used for testing must live under `test2` (or the rule needs a second namespace). | `lab2/scripts/Manage-LabDns.ps1` |

## 1.3 What is worth keeping

- **The Brain table family** (`brain_dns_zones`, `_subdomains`, `_servers`, `brain_http_servers`, `_tenants`) — extend, do not replace.
- **The zone JSON shape** `{domain, default_ttl, soa, nameservers, include, records[{name,type,value,ttl}]}` and its
  legacy reader (`InfraDnsRules`), plus `InfraDnsRules::nextSerial`.
- **The signed Brain surface** (`BrainSurfaceClient`, `brain.surface:MEMBERS`) and the reverse `www.internal` callbacks.
- **The Name.com adapter body** (`_searchNameCom`, `_runNameCom`) — wrap it behind an interface (05 §5.3).
