# 09 — lab2 environment: completion report (domain name management)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/09-lab2-environment.complete.md](../../new_way/complete/09-lab2-environment.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: P0 built except the pieces that need the Brain code on the lab Brain VM (the worker and scheduler are written, tested as a dry run, and NOT installed). Acceptance 09 §9.9 items 1–4 are proven on the real lab; items 5–8 are proven on the real web VM with a stand-in template and a fake registrar; item 9 is covered by the step 02/04 tests; item 10 (move) is step 12 and not built.**
> Carried out 2026-10-09 against [09-lab2-environment.md](../09-lab2-environment.md). Nothing is committed. Code is in `w/www` and `C:\projects\vagrant\lab2`.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

| Plan section | Built as |
|---|---|
| §9.1 seeder | `php artisan domains:seed-lab [--dry-run]` — refuses unless the setting `env` is `lab2` and `allowed_server_cidrs` is not empty; sets every **DNS and web server row outside the allowed networks inactive** (never deleted); creates the lab rows **only when missing** (DNS `10.0.0.242`; web `10.0.0.244` as a Head that takes tenants, capacity 5; Brain `10.0.0.243` taking none). Rows it creates have **no login**: the Brain logs in with the server's own record, so the owner adds the ssh user and password, and until then the Brain refuses to contact it and says which server. Idempotent. Run against the real lab database: **0 changes** (it was already safe, 09 §9.1 status note) |
| §9.1 guard | The transports already refused any address outside `allowed_server_cidrs` (steps 04/07). Added the other half: `DnsGuard::offendingWebServers()`, and `DeploymentService::accept()` now **refuses every new website while any ACTIVE web server is outside the allowed networks** (`DOM_E_FLEET_UNSAFE`, 503, no address in the message, logged). The DNS publisher already did the same for DNS servers (`blocked`) |
| §9.2 configuration | Done in E7 (Brain settings, no `.env`); lab values in 09 §9.2. 95 settings now (the two `new_flow.*` keys of step 10 were added) |
| §9.4 "the customer's own DNS provider" | `lab2/dns/customer-dns.sh` (on the DNS VM: `init`, `add <fqdn>`, `txt <fqdn> <value>`, `remove <fqdn>`, `list`) and `lab2/scripts/Lab-CustomerDns.ps1`. BIND includes `/var/named/customer-zones.conf`; **the DNS agent refuses (exit 3, "still served by another provider") to publish a name in it** — the agent's default `FOREIGN_CONF` is now `<named dir>/customer-zones.conf`, a file that exists only in the lab, so on a production server the check is inert. Installed and used on the running DNS VM |
| §9.5 DNS VM | Agent re-installed (new default above); `provision/dns.sh` installs the harness. The rest of §9.5 was done in step 04 |
| §9.8 registrar | `fake` driver (step 05); the lab setting `registrar_driver = fake` |

## 2. Files

`C:\projects\vagrant\afs-eco\w\www`: `app/Console/Commands/DomainsSeedLab.php`; `app/Services/Brain/Domains/Dns/DnsGuard.php` (+`offendingWebServers`), `app/Services/Brain/Deploy/DeploymentService.php` (the fleet check); `core/scripts/dns/afs-dns-agent` (default foreign conf); tests `SeedLabAndFleetGuardTest` (5), `Dns/LabConnectFlowTest` (1, opt-in), `DnsAgentScriptTest` (+1), `Deploy/LabDeployTest` (parking assertions); `tests/Support/lab_dig.cmd` (a stand-in for `dig`, test-only).

`C:\projects\vagrant\lab2`: `dns/customer-dns.sh`, `scripts/Lab-CustomerDns.ps1`, `dns/afs-dns-agent` (copy), `provision/dns.sh`, `web/install-parking.sh`, `provision/tenant-host-setup.sh`, `provision/brain-worker.sh`.

## 3. Acceptance, item by item (09 §9.9)


| # | Item | Result |
|---|---|---|
| 1 | Seeder; the guard refuses a deliberately wrong server row | **Proven.** `SeedLabAndFleetGuardTest` (production-style active rows are deactivated, not deleted; lab rows created; idempotent; dry run changes nothing; refuses outside `lab2` and without an allowlist; **publishing and new websites are refused while a production-address row is active**, and resume after the seeder). On the real lab database the dry run reports 0 changes |
| 2 | Connect a name: pending → TXT verified → active; `site_ref`, `<ref>.sites.test2`, `dig` answers, the stanza in `named.conf` | **Proven on the real DNS VM** by `LabConnectFlowTest`: the customer's provider is the harness; the verifier asks the real authoritative server with a real `dig` (first `token_missing`, then active once the customer publishes the token); the zone and sites jobs run through the real agent; `dig @10.0.0.242` returns the serial the Brain wrote, `NS ns1.test2.`, the proof TXT and the second name at the parking address. (`LabDnsPublishTest` covers the stanza and record edits.) |
| 3 | Member B claims the same name: Verify fails with a reason, B stays contested, A untouched | **Proven** in the same test: B's response has the same shape as A's, its token is different, B's verify is `token_missing`/`token_mismatch`, A stays active |
| 4 | A edits a record: serial advances, `dig` shows it, system records locked | **Proven for the DNS part** (`LabDnsPublishTest`: serial, `shop` A record, MX). "System records locked" is proven at the API (`zone()` marks them `locked`, `replaceRecords` cannot touch them); the screen was **not** opened in a browser |
| 7 | Buy with the fake registrar; a decline refunds | **Proven in the tests** (`DomainPurchaseTest`, `RegistrarDriversTest`, fake billing and registrar). Not through the screen |
| 9 | Release: zone and stanza withdrawn, `site_ref` retired, re-adding gives a new `site_ref` | **Proven on the real DNS VM** for the withdrawal (`LabDnsPublishTest`: sites name dropped first, zone gone, file in `.trash`, stanza gone); the retired and never-reused `site_ref` is covered by the step 02 tests (`DomainClaimsTest`) |

## 4. What the lab turned out to be (checked, not assumed)

- **The stored lab logins work.** Using the exact passwords in the lab database's server records, the real runner (Brain VM → DNS VM, Brain VM → web VM, as `root`) ran `afs-dns-agent version` and `afs-tenant-agent ping` — so, once the code is on the Brain VM, the Brain can reach both servers with no key. (`PermitRootLogin yes` and `PasswordAuthentication yes` are on all three VMs.)
- **The lab Brain runs neither a queue worker nor the scheduler.** No cron entry for any user, no `queue:work`, no `schedule:run`; only `crond` and Redis. The queue connection is `database` (the plan assumed Redis). So nothing scheduled runs on the lab Brain at all today, domains or otherwise; `brain-worker.sh` is the fix, written and dry-run-tested.
- **The Brain code on the lab Brain VM is the old code** (`/home/appfactorystudio/websites/www`): none of steps 02–10 is there. That is why the worker script was not installed and why nothing in this report was run *on* the Brain VM except the ssh runner. Putting the code there is the next real step.

## 5. Where the build differs from the plan text

1\. **The harness customer zone uses the platform's own nameserver name** (`ns1.test2.`), because naming another nameserver would need an A record in the live `test2` zone. So a harness zone looks "delegated to the platform" to the verifier (it only affects `managed`/`external`, not identity). `AFS_CUSTOMER_NS` overrides it.

2\. **No `Lab-CustomerDns` bash twin on the PC** — the PowerShell wrapper and the on-VM script are the pair.

3\. **The seeder never invents a login** and never overwrites an existing row's capacity or login.

4\. **The boot-time fleet check** is enforced where it matters (the publisher's `runDue` and the deploy `accept`) instead of in a service provider, so a bad fleet stops the operation and says so rather than silently disabling features at boot.

5\. **`deploy-tenant.sh` is not retired** (P1, and the owner's Path A may still use it); `add-zone.sh`/`deploy-tenant.sh` still append to the sites include, which the Brain's adopt mode tolerates (step 04).

## 6. Verification

Real MariaDB and the real lab VMs. One run of the whole Domains folder + the migration guardrail + the config-rules tests with all opt-in lab tests switched on: **272 tests, 3,897 assertions; 270 passed in that run.** The two that failed were both lab-DNS tests and the cause was a defect in the NEW `LabConnectFlowTest`: it left its line in the shared sites list on the DNS VM, and the next test's first publish (adopt mode, which refuses to drop a line it did not write) failed. The test now releases its claim at the end (which also proves 09 item 9 through the flow) and its tear-down removes its line; the stale line was removed, and the three lab-DNS runs (connect, publish, connect again, in that order) then passed: 3 tests, 71 assertions, and the sites list is unchanged afterwards. The whole folder was **not** run a second time after that fix.

## 7. Not verified, honestly

- Anything running **on** the lab Brain VM: the code, the worker, the scheduler, `domains:*` commands there.
- A **production** DNS or web server (the guard, the seeder and the harness are lab-only or inert there).
- `Lab-CustomerDns.ps1` was written but **not run** (the shell script it wraps was).
- The Vagrant provisioners (`dns.sh`, `tenant-host-setup.sh`, `brain-worker.sh`) were checked with `bash -n` and, for the installers, by running their scripts on the running VMs; a **fresh `vagrant up`** was not run.

## 8. To do next, in order

1\. **Sync the Brain code to the lab Brain VM** (the owner's sync), run `domains:settings`, `domains:seed-lab --dry-run`, `provision/brain-worker.sh`, and repeat acceptance items 5–6 from the Brain VM itself.
3\. Add the ssh logins to any server row the seeder created.
