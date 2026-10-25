# 09 — lab2: what it needs, and the hazard to remove first (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/09-lab2-environment.md](../new_way/09-lab2-environment.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The shared hazard and guard (§9.1), the settings, the test names, the DNS VM and the domain acceptance items are here; the tenant host, the website worker and the website acceptance items are in the website-deployment half.

lab2 (`C:\projects\vagrant\lab2`) is where this is built and proved. It already has the right shape — a DNS VM running
BIND, a Brain VM running the estate, a plain tenant host — and a tenant-deploy tool of its own. It is missing the pieces
this plan adds, and its seed data is unsafe for it.

```
 lab2-dns   10.0.0.242  BIND, zone test2 (+ tenants.zone for sites.test2)                      ← member zones published here
 lab2-brain 10.0.0.243  nginx+Apache+php-fpm, MariaDB, Redis, the apps (www = Brain, members)   ← claims, publisher, deploy runner, queue
 lab2-web   10.0.0.244  nginx :443 → Apache :808 → php-fpm, MariaDB, Node; tenants under /home   ← the HTTP server sites land on
```

## 9.1 The hazard: production servers in the lab database  *(do this before anything else)*

> **Status 2026-10-08 (corrected).** The seed file described below still contains the production rows, so **a freshly built lab would load
> them**. The **running** lab2 database no longer does: `brain_dns_servers` has one row (`10.0.0.242`, set **active** on 2026-10-08, so the DNS step connects to the lab DNS VM) and `brain_http_servers` has `10.0.0.243` (Brain) and `10.0.0.244` (Head, `node1.appfactory.studio` by name), so
> "Create website" would pick `web`. Old tenant rows still point at `3.130.107.19` and `45.79.42.137`. An earlier note here said the live
> database was affected; that was read from the seed file, not the live tables.

`lab2/databases/data-only/v2/testdb_appfactorystudio.data-only.sql` — the data the `db-import` provisioner loads into the
shared database — contains, **as active rows**:

- `brain_dns_servers`: one **active** row, `45.79.42.137` (root login stored; the same address the live `appfactory.studio` zone
  uses for `ns1`/`ns2`), plus two inactive rows;
- `brain_http_servers`: **three active rows** - `45.79.42.137` (`server1.cradle2careertx.org`, type `Brain`),
  `3.130.107.19` (`node1.appfactory.studio`, type `Head`; also the address of a live entry in the production sites include)
  and `3.138.213.208` (type `Head`) - plus one inactive `Head`.

`createWebsite()` takes the **first active `Head` server in table order** (`node1.appfactory.studio`, `3.130.107.19`) and SSHes to it; the DNS step connects to the active DNS
server. **A "create website" run in lab2 against this seed acts on a production host.** The same applies to any new
publisher or runner unless they are told otherwise. This is not about lab secrecy; it is about the lab being able to
change production.

**Fix, in two layers (both P0, before any code in 04/07 is run):**

1. **Seeder.** `php artisan domains:seed-lab` (Brain, refuses to run unless `DOMAINS_ENV=lab2`) sets `active = 0` on every
   `brain_dns_servers` / `brain_http_servers` row whose address is outside `10.0.0.0/24` and upserts the lab rows:
   - DNS: `10.0.0.242`, family 1, key-based login.
   - HTTP: `web` `10.0.0.244`, `server_type = Head`, `accepts_tenants = 1`, `max_tenants` small (e.g. 5) so capacity and
     `waiting_capacity` can actually be exercised.
   - HTTP (optional, for selection tests): `brain` `10.0.0.243`, `accepts_tenants = 0` by default.
   Rows are deactivated, not deleted, so re-running `db-import` (`INSERT IGNORE`) cannot resurrect them differently.
2. **Guard in the code.** `config('domains.allowed_server_cidrs')` is mandatory when `DOMAINS_ENV` is set; the DNS and agent
   transports resolve the target and **refuse any address outside the allowlist** before opening a connection; a
   boot-time check logs and disables publishing/deploys if any *active* server row is outside it. Production's allowlist is
   its fleet; the lab's is `10.0.0.0/24`. A wrong `.env` then fails loudly instead of reaching 45.79.42.137.

Also: the seeded lab database holds production credentials in `brain_*` rows. Whether to scrub them from the `.sql` file
is the owner's call; the guard above is what makes them harmless to the pipeline.

## 9.2 Configuration — in the Brain settings, not in `.env`

**Every domains and website-building setting lives in the Brain settings document** (`brain_config` > `controller.domains`), edited in the Brain settings editor
(Controller > Domains; schema `w/www/resources/js/portal/admin/afscore/schema/brain_controller_schema.ts`). There is no `.env` key, no default in code and no fallback:
a setting that is not there stops the operation and names itself (`DOM_E_SETTING_MISSING`). `php artisan domains:settings` lists all 95 and reports any that are missing or of the wrong type.
`.env` still holds what is not a domains setting (`QUEUE_CONNECTION`, `LETSENCRYPT_CHECK` for the old pipeline, `IS_BRAIN`).

The lab's values, written to the lab database on 2026-10-09 (backup table `_backup_20261009_brain_config`); only what differs from the editor's example values:

```
env                          lab2
platform_ns                  ns1.test2
reserved_names               test2, ns1.test2, dns.test2, brain.test2, web.test2, sites.test2
reserved_suffixes            sites.test2
extra_allowed_suffixes       10-0-0-243.sslip.io, test2           (lab names under these are registrable apexes, 02 §2.9)
verify_resolvers             10.0.0.242
sites_zone                   sites.test2          platform_zone_parent  test2        hostmaster  admin.test2
parking_ipv4                 10.0.0.243           (tenants are hosted on brain in the lab, D18a)
publish_enabled              true                 named_conf_mode  stanza        zone_dir  /var/named/zones
sites_include_file           test2.include.zone
allowed_server_cidrs         10.0.0.0/24
registrar_driver             fake
dns_agent / tenant_agent     ssh_runner /home/appfactorystudio/websites/www/core/scripts/ssh/afs-ssh-run, sudo false   (no keys: the login is on each server's record)
```

## 9.3 Test names

The owner's lab convention is **`tenantN.10-0-0-243.sslip.io`**. `sslip.io` answers `10.0.0.243` (the brain VM) for any such name from
anywhere, so a browser, a phone or a VM reaches the site with no DNS setting; and because the lab's DNS server is also made
authoritative for each name (zone file + zone stanza in `/etc/named.conf`), the real sequence — zone, check, reload, query — can be run and
checked with `dig @10.0.0.242`. The records are chosen so both answers agree.

- **Hosting:** the sites run on the `brain` VM (D18a), which already holds the `*.10-0-0-243.sslip.io` wildcard certificate; the apex has
  HTTPS, `www.<name>` is HTTP-only (one label too deep).
- **Second name:** `<site_ref>.sites.test2`, with the `tenants` wildcard certificate installed on brain. Browsing it needs the
  `.test2` resolver rule (`Manage-LabDns.ps1 -Add`) on the PC; otherwise test with `curl --resolve`.
- **Normaliser:** `10-0-0-243.sslip.io` and `test2` are configured as extra suffixes (§9.2), otherwise `tenant1.10-0-0-243.sslip.io` would be
  refused as a subdomain of `sslip.io` (02 §2.9 rule 2).
- Names under `test2` (`acme.test2`) remain valid for tests that need a name only the lab DNS can resolve.

## 9.4 Playing "the customer's own DNS provider"

Connect-flow tests need a place where the *member* publishes the TXT record before the platform serves their zone — and the
collision tests need to show that a second member **cannot** place theirs. One DNS VM has to play both roles, so a lab-only
helper does it:

- `lab2/scripts/Lab-CustomerDns` (PowerShell + bash twin) manages `/var/named/customer-zones.conf` and
  `/var/named/customer/<fqdn>.zone` on the DNS VM: `add <fqdn>`, `txt <fqdn> <token>`, `remove <fqdn>`. These are the
  zones of the member's *old* provider.
- BIND includes both `customer-zones.conf` and `member-zones.conf`. The publisher **refuses to publish a zone whose name
  is currently in `customer-zones.conf`** (it reports "the name is still served by the customer's provider"); the harness's
  `remove` is "the customer moved their nameservers to us".
- Result: the two-member collision test is faithful. Member B's token cannot be put in A's zone (A's zone is the only one
  that exists for the name), and the verifier, asking the authoritative server, correctly says no.

This helper exists only in lab2; production has no equivalent and needs none.

## 9.5 Changes to the DNS VM (`provision/dns.sh`, `dns/*.zone`)

- `named.conf`: **zone stanzas are appended to `/etc/named.conf`** (04 §4.4, `stanza` mode), one marked block per zone — nothing is
  included in advance. `rndc` already works on the loopback. The harness (§9.4) adds `include "/var/named/customer-zones.conf";`.
- **Production zone layout:** every zone file lives in `/var/named/zones/` — `test2.zone` (the platform parent, = production `appfactory.studio.zone`), `test2.include.zone` (the sites list with `$ORIGIN sites.test2.`, = `appfactory.studio.include.zone`, `$INCLUDE`d by full path from `test2.zone`) and one `<name>.zone` per domain — and the stanzas in `/etc/named.conf` say `file "zones/<name>.zone";`. `dns/migrate-to-zones-dir.sh` moved the running server to this layout (with a backup and rollback); `provision/dns.sh` builds it from scratch.
- Create `/var/named/{.staging,.trash}` and `/var/named/customer/` (owned by `named`) when the publisher is built.
- Install `afs-dns-agent` (04 §4.1) to `/usr/local/bin` and authorise the Brain's key for the deploy user.
- **Stop clobbering live data.** Today re-running `dns.sh` re-installs `tenants.zone` over the live one (F39). Seed files are
  installed only when the target is absent.
- **`zones/test2.include.zone` is the sites list.** `dns/add-zone.sh` (today) and the Brain's `sites` job (later) write `<site_ref> IN A <ip>` into
  `/var/named/zones/test2.include.zone` and bump the `test2` serial, because that file is `$INCLUDE`d. `deploy-tenant.sh` also appends there; until it is
  retired (step 10) the Brain's first render runs in adopt mode (10 §10.4 item 6) so it never drops a line it did not write.
- The test2 zone's own records (`ns1`, `dns`, `brain`, `web`) are unchanged.

> **§9.6 (changes to the tenant host)** is in the website-deployment plan: [../new_way/09-lab2-environment.md](../new_way/09-lab2-environment.md).

## 9.7 Changes to the Brain VM

- A supervised worker for the `deploy` and `dns` queues (a service definition in `provision/`, started like the other services)
  and the scheduler entry for the verifier, publish-reconcile, reservation sweep and purchase reconciler. `routes/console.php`
  already carries scheduled work, so this adds entries rather than a new mechanism.
- No change to the apps' web serving.

## 9.8 Registrar in the lab

`DOMAINS_REGISTRAR_DRIVER=fake` (05 §5.3): deterministic, free, covers success, unavailable, timeout and decline. The purchase
screen, the saga, the refund and the reconciler are all testable with no card and no registrar account. A run against the
Name.com sandbox is a separate, deliberate step (needs credentials) done once before production.

## 9.9 Acceptance in lab2 (P0 exit)

`tenant1`–`tenant3` already exist (§9.11) and serve as fixtures for items 5, 8, 9 and 10. Items 2, 3 and 7 use fresh names
(`tenant4.10-0-0-243.sslip.io`, `tenant5…`).

1\. `domains:seed-lab` run; the guard refuses a deliberately wrong server row.
2\. Member A connects `tenant4.10-0-0-243.sslip.io`: claim pending → the TXT is verified (through the harness for an external provider, or the
   platform `_afs-verify` record once managed) → active; `site_ref` and `<ref>.sites.test2` exist; `dig @10.0.0.242 <ref>.sites.test2` answers
   and the zone stanza is in `/etc/named.conf`.
3\. Member B claims the same name; Verify fails with a clear reason; B stays contested; A is untouched.
4\. A edits a DNS record in the UI; the serial advances; `dig @10.0.0.242` shows it; the system records are locked.
7\. Buy `shiny.10-0-0-243.sslip.io` with the fake registrar: charge, register, NS, active, site; and a decline refunds.
9\. Release the domain; the zone and its stanza are withdrawn; `site_ref` is retired; re-adding the name makes a **new** `site_ref`.

(Items 5, 6, 8 and 10 — create a website, kill and cancel mid-deploy, delete the website, move a site — are in the website-deployment plan.)

## 9.11 What exists in the lab today (built 2026-10-07; recorded in `complete/00_extras.md` E1–E3)

Interim tooling, written before the pipeline so the process could be run by hand. It is the working reference for what the DNS publisher
and the tenant steps must reproduce; the pipeline replaces it, it does not need to be kept in step with it.

| What | Where | Does |
|---|---|---|
| `lab2/dns/lab-domains.list` | host | The three test domains: `tenant1`–`tenant3.10-0-0-243.sslip.io`, each with a `site_ref` and `10.0.0.243` |
| `lab2/dns/add-zone.sh` | runs on the DNS VM | Zone file in the `test2.zone` structure (SOA `ns1.test2.` / `admin.test2.`, `YYYYMMDDnn` serial, NS, `@ A`, `www CNAME <ref>.sites.test2.`, `_afs-verify` TXT); marked zone stanza appended to `/etc/named.conf` (original saved as `named.conf.pre-lab-domains`); `<ref> IN A` into `zones/test2.include.zone`; `test2` serial bump; `named-checkzone`/`named-checkconf`; `rndc reconfig` + reload; `dig` checks |
| `lab2/brain/register-lab-domain.php` | runs on the brain VM | `brain_dns_zones`, `brain_dns_subdomains`, `brain_http_server_tenants`, `members_websites` rows under member `james1` (`m1xTHpRmdtH2Y`) |
| `lab2/dns/migrate-to-zones-dir.sh` | runs on the DNS VM, once | Moved `test2.zone`, the sites list (now `test2.include.zone`) and the three domain zones into `/var/named/zones/`, rewrote the stanzas and the `$INCLUDE` path; backed up first, rolls back on a failed check (done 2026-10-08) |
| `lab2/provision/dns.sh` | DNS VM provisioner | Builds the `zones/` layout and re-creates the stanzas and zones from the list on re-provision; never overwrites a live `test2.include.zone` |
| `lab2/dns/afs-dns-agent`, `lab2/dns/install-agent.sh` | DNS VM | The Brain's DNS agent (04 §4.1; canonical source `w/www/core/scripts/dns/afs-dns-agent`) and its installer, which also creates `/var/named/.staging` and `/var/named/.trash`; `provision/dns.sh` runs it. **Installed on the running DNS VM 2026-10-08** and used by the end-to-end test (`complete/04-dns-zone-engine.complete.md` §7). From then on the sites include `zones/test2.include.zone` is **rendered by the Brain**; `add-zone.sh` and `deploy-tenant.sh` still append to it and must stop (step 10) |

State after the run: the three zones answer authoritatively on `10.0.0.242`; the three sites answer on the apex (HTTPS, verified) and on
`<ref>.sites.test2`; `www` answers over HTTP; the existing apps are unaffected. The hazard in §9.1 is fixed in the **running** database (server rows point at lab hosts) but **not in the seed file** a fresh lab loads.

## 9.10 Phases

| Phase | Work |
|---|---|
| **P0** | §9.1 seeder and guard; §9.2 settings; §9.4 harness; §9.5 DNS VM; §9.7 scheduler; `fake` registrar; acceptance §9.9 items 1–4 |
| **P1** | Items 7 and 9; Name.com sandbox run |
| **P2** | A second DNS VM to try primary/secondary |
