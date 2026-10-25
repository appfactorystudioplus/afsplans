# 04 — DNS zone engine: completion report

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

> **Status: built and tested (P0 and P1, including the lab end to end). P2 not built.** Carried out 2026-10-08 against [04-dns-zone-engine.md](../04-dns-zone-engine.md), on
> [step 03's data model](03-data-model.complete.md) and [step 02's claims](02-domain-identity-and-collisions.complete.md). Nothing is committed. The code is in `w/www` (the Brain);
> the DNS agent also lives in `lab2/` for provisioning. `members` is untouched.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

An active claim becomes a zone on the DNS servers, **and its second name (`<site_ref>.sites.<zone>`) appears in the platform's sites list**, without anybody appending a line to a
file. The flow, as built:

```
 claim becomes active ──(same transaction)──▶ sites-list entry + serial bump + publish jobs (outbox)
                                                         │   php artisan domains:publish  (every minute, Brain only, off by default)
                                                         ▼
   DnsPublisher ─ render (pure) ─ validate ─ ssh ─▶ afs-dns-agent on each DNS server:
                                                    stage ▶ named-checkzone ▶ atomic rename ▶ stanza ▶ rndc ▶ read the serial back
   done only when EVERY server in the family reports a serial ≥ the desired one
```

| Plan section | Built as |
|---|---|
| §4.1 parts | `ZoneRenderer`, `ZoneValidator`, `DnsPublisher`, `DnsTransport` (`SshDnsTransport`), `afs-dns-agent`; `DomainDnsSync` is the outbox |
| §4.2 system versus member records | platform records **computed at render time** (`ZoneRenderer::platformRecords`), member records in `ZoneRecords`; the rules of §4.2 enforced for the API and, as "leave this one record out", at render |
| §4.3 the serial | `DomainDnsSync::bumpSerial()` in the caller's transaction, `InfraDnsRules::nextSerial`; the legacy `json_zone_data.soa.serial` copy follows |
| §4.4 publish algorithm | steps 1–6 as written; both `named_conf_mode`s (`stanza` default, `include`) in the agent |
| §4.5 the second name | created **at activation**; `SitesIncludeRenderer` renders the include in full; parking address before a site; D7 switch |
| §4.6 member DNS API | `ZoneRecordService::zone()` / `replaceRecords()` (service; the signed routes are step 08) |
| §4.7 reconcile and drift | `DnsReconciler` + `php artisan domains:dns-reconcile` (every ten minutes) |
| §4.8 withdrawal | `withdraw` job; the order site offline → sites record gone → zone withdrawn enforced |
| §4.9 multiple DNS servers | push to every active server of the zone's family (primary/secondary is P2) |
| §4.10 testing | golden files, `named-checkzone`, serial property test, publisher on a fake transport, **and against the real lab DNS VM** |
| 09 §9.1 guard | `DnsGuard`: a server outside `DOMAINS_ALLOWED_SERVER_CIDRS` is never contacted; mandatory when `DOMAINS_ENV` is set |
| 10 §10.4 item 6 | adopt mode on the first sites publish |

## 2. Files

All paths under `C:\projects\vagrant\afs-eco\w\www` unless stated.

**Engine** (`app/Services/Brain/Domains/Dns/`)
- `ZoneRecords` — the member's records: reads both stored shapes (the UI's object values and the legacy lists), the §4.2/§4.6 rules, writes back in the shape the screens store.
- `ZoneRenderer` — pure function, claim facts → zone text (golden-file tested). `SitesIncludeRenderer` — the include, rendered in full.
- `ZoneValidator` — PHP structure rules, plus `named-checkzone` when the Brain host has it.
- `DomainDnsSync` — the outbox: `claimHolds`, `claimReleased`, `recordsChanged`, `modeChanged`, `siteChanged`, `bumpSerial`, `enqueue`. **Step 07 and 12 call `siteChanged()`.**
- `DnsPublisher` — runs the jobs (lock, claim, render, push, confirm, retry with backoff, supersede, withdraw ordering, adopt mode).
- `DnsTransport` (interface), `SshDnsTransport`, `AgentResult`, `DnsGuard`.
- `DnsReconciler` — drift, unknown zones, broken invariants. `ZoneRecordService` — the member-facing calls. `ZoneJson` — the TEXT-JSON convention.
- `app/Services/Brain/Domains/DomainClaimService.php` — **changed**: it takes `DomainDnsSync`, and `setStatus()` calls it (§3.1). `present()` also returns `sites_name` and `publish`.
- `app/Console/Commands/DomainsPublish.php`, `DomainsDnsReconcile.php`; `routes/console.php` schedules them (every minute / ten minutes, Brain only); `AppServiceProvider` binds `DnsTransport`.
- `config/domains.php` (the keys of 03 §3.9), `_env/lab2/.env` (lab values; the live `.env` was not touched).

**The agent**
- `core/scripts/dns/afs-dns-agent` — **canonical** (bash). `C:\projects\vagrant\lab2\dns\afs-dns-agent` is a byte-identical copy for provisioning.
- `C:\projects\vagrant\lab2\dns\install-agent.sh` — installs it and the two work directories (idempotent; changes no zone and no `named.conf`); `lab2/provision/dns.sh` calls it.

**Tests** (`tests/Feature/Brain/Domains/Dns/`): `ZoneRecordsTest`, `ZoneRendererTest` (+ `tests/fixtures/domains/zones/*.zone`), `DnsSerialTest`, `SshDnsTransportTest`, `DnsAgentScriptTest`,
`DnsPublishingTest`, `LabDnsPublishTest` (opt-in, `AFS_LAB_DNS=1`); `tests/Support/InMemoryDnsTransport.php`.

## 3. How it behaves

### 3.1 Hooks in the claim service
`DomainClaimService::setStatus()` is the one place a claim's status changes, so it is the one place that records DNS work, in the **same transaction**:
- a claim that **starts holding** the name (activated, promoted, transferred in): the second name's entry in `brain_dns_subdomains` (pointing at `parking_ipv4`, or at the website's server if there is one), a serial bump, a `zone` job (managed DNS only) and a `sites` job;
- a claim that **stops holding** it (released, superseded, lapse grace over, transferred out): the entry is `retired`, then a `sites` job, then a `withdraw` job;
- a change between **managed and external** DNS: publish or withdraw the zone. An external domain gets its sites name but no zone;
- a challenge issued to a holder (a legacy holder that is contested) re-publishes, because the proof record the zone carries changed.

A claim that has not proven control publishes **nothing** (asserted: no job, no list entry, no server call).

### 3.2 The zone
Computed, never stored: `SOA` (mname = first platform NS, rname = the hostmaster), apex `NS` (all platform NS), `@ A` **only while a website is active** (D7a), `www CNAME <site_ref>.<sites_zone>.`,
`_afs-verify TXT` (kept published for the life of a managed active claim, so re-verification still answers once the NS are ours), and a reserved MX slot (rendered if given; nothing wires it).
Member records follow in a stable order. **A record that breaks a rule is left out of the zone and named in the job result** (`dropped`), so one bad legacy record cannot stop a zone loading.
Warnings (not refusals) are also recorded: an MX / SRV / CNAME target inside the zone that has no address record (audit F11 — the old default MX).

### 3.3 The agent (`afs-dns-agent`)
`put-zone`, `remove-zone`, `put-include`, `rebuild-conf`, `status`, `version`. Every change is stage → check → atomic rename; a failed check leaves the live file untouched. It writes one **marked**
stanza (`// afs-zone: <name>`) to `/etc/named.conf` (first change saves `named.conf.pre-afs`), recognises the lab's older `// lab-domain:` marker so existing lab zones are adopted,
**refuses to touch a stanza it did not write**, refuses a name that appears in `AFS_DNS_FOREIGN_CONF` (zones another provider serves), moves a replaced or removed zone file to `.trash` (never deletes), and
for the sites include refuses to drop a line it was not allowed to (below), bumps the **parent** zone's serial, and checks the parent with the new include **before** reloading, restoring the previous include if BIND would refuse it.
Exit codes: `0` done · `2` BIND's own check refused it · `3` the agent refused it · `4` BIND could not load it.

### 3.4 Withdrawal and ordering
A `withdraw` job (a) is **superseded** if any claim now holds the name with managed DNS (a promoted contender's own publish owns the file — a removal would delete their zone), and (b) otherwise
**waits until the newest `sites` job is done**, so the sites record is gone before the zone is. Waiting is not an attempt. A failed sites job therefore blocks the withdrawal until it is re-queued (`domains:publish --retry=ID`).

### 3.5 Adopt mode for the sites include
Until one `sites` publish has completed, the agent is called with `--no-drop`: it refuses to replace an include that would lose a line the database does not have (exit 3, which the Brain treats
as "do not retry quickly"). `DOMAINS_SITES_ACCEPT_DROP=name,name` names lines known to be stale. After the first clean publish the check relaxes to a note on stderr.

### 3.6 Failure behaviour
A job retries with backoff (30 s doubling, capped at one hour, 8 attempts by default); the servers that succeeded keep serving; `publish_state` is `published` only when every server confirmed, `pending` while retrying, `failed`
after the last attempt (and a `publish_failed` event with the reason). A worker that died mid-job leaves it `running`; after ten minutes it is due again (every agent step is idempotent). Publishing is **off** when
`DOMAINS_PUBLISH_ENABLED` is false (jobs stay recorded), and **blocked** when any active DNS server is outside the allowed networks.

## 4. Where the build differs from the plan text

1. **Scheduled, not dispatched.** The plan says "a queued worker". Jobs are recorded in the outbox and run by `domains:publish` every minute (`withoutOverlapping`, `onOneServer`), so a change reaches DNS within about a minute. An immediate dispatch after commit is an easy later addition; the outbox needs no change.
2. **Servers one after another, not in parallel.** The loop in `DnsPublisher::pushAll()` is the only place that would change for bounded parallelism (P2 in effect).
3. **`publish_enabled` is off by default.** Production's DNS servers need the agent installed first (a runbook step); jobs are recorded meanwhile. The lab turns it on in `_env/lab2/.env`.
4. **The apex rule is slightly narrower than the plan's wording.** The plan says "no record at a name a system record owns (`@ A`, `www`, `_afs-verify`)". `www` and `_afs-verify` are always the platform's; `@ A`/`AAAA` belong to the platform **only while a website is deployed** (D7a: before that the platform publishes no apex address and a member may keep their own).
5. **The sites job's serial is its job id** (monotonic and unique), carried in the include's first line (`; afs-sites-serial: N`) and read back by the agent; a job is done when the server reports `include_serial ≥ N`. The include carries an explicit TTL on every line because a `$TTL` in an included file would leak into the platform zone.
6. **A CNAME beside another record is dropped, the other record wins** (the plan only says "no CNAME next to another record"); the dropped one is named in the job result.
7. **Reconcile reads the servers through the agent's `status`**, not `dig` from the Brain, so it works wherever publishing works. An invariant finding is recorded once per day per claim, not every ten minutes. Unknown zones are **reported, never deleted**.
8. **The boot-time check of 09 §9.1 is a check at every run**: if any active DNS server is outside the allowed networks, `domains:publish` does nothing and says so. It does not run at application boot.
9. **The first publish of existing holders is explicit.** `domains:backfill-tenants --apply --enqueue-publish` creates each holder's sites-list entry and queues its zone and sites jobs; nothing does it silently.
10. **No HTTP routes.** `ZoneRecordService` is the member-facing API as a service; the signed routes, the members pass-through and the removal of the generic `json_patch` (which can still set `has_website`, `site_enabled`, `provider`) are **step 08**.
11. **`include` mode is implemented and tested in the agent but nothing selects it** (`stanza` is the default and the owner's choice). `rebuild-conf` and the `named_conf` job kind exist for repair; no code enqueues one.
12. **The lab "customer's own DNS provider" harness (09 §9.4) is not built.** The agent's `AFS_DNS_FOREIGN_CONF` guard that it relies on is.

## 5. What is not built (and why)

- **P2:** record import, primary/secondary with TSIG, RFC 2136 for the sites zone, the DNS-01 hook for the wildcard certificate.
- **The mail records** (the slot renders; `membership_change` feature 2 owns what goes in it) and the **parking host** page itself (a tenant-host job: step 09 §9.6 / step 07).
- **The staff infra screen** for reconcile output, the **members UI** changes and the **runbook for the production bootstrap** (step 08 and step 10). Reconcile results are in the command output and in `brain_domain_events`.
- **A real password-fallback run.** The `sshpass` path is implemented and tested with the process faked; `sshpass` is not installed on this machine and was not installed (it is a Brain-host dependency for production servers that have no key yet).
- **`named-checkzone` on the Brain host** is optional and was not present on this machine; the agent runs the real one on the DNS server every time, and the goldens were checked on the lab VM (§6).

## 6. Verification

Run **once at the end**, against the real lab MariaDB (`10.0.0.243`, the throwaway `testdb_phpunit_www`; no SQLite) and, for the opt-in file, the real lab DNS VM (BIND 9.16.50).

| Result | |
|---|---|
| Step 04 tests | **74 tests, all passing** — `ZoneRecordsTest` 14, `ZoneRendererTest` 10, `DnsSerialTest` 3, `SshDnsTransportTest` 8, `DnsAgentScriptTest` 16, `DnsPublishingTest` 22, `LabDnsPublishTest` 1 |
| Whole `tests/Feature/Brain/Domains` + `tests/Unit/Database` | **131 tests, 2,781 assertions, all passing** (step 02: 44, step 03: 9, step 04: 74, `MigrationOwnershipTest`: 4), one run, with the lab test enabled |
| Golden files in real BIND | every golden zone loaded with `named-checkzone` on the lab DNS VM (`zone example.com/IN: loaded serial 2026100801 OK`); the only output is BIND's warning that an MX/SRV target inside the zone has no address record, which is the warning the renderer now reports (audit F11) |
| The real agent script | `DnsAgentScriptTest` runs `afs-dns-agent` under bash on a scratch directory laid out like a BIND server with stand-ins for the checkers: idempotent re-runs, one marked stanza, a failed check leaves the live file and the config byte-identical, remove restores `named.conf` byte-identical and moves the file to `.trash`, refusal of unmanaged stanzas / foreign names / bad names / path escapes / a mismatched serial, adopt-mode refusal and accept list, the previous include restored when the parent would not load, include mode, `status` |
| Publisher on a fake server | outbox on activation (zone + sites jobs + list entry in one transaction), all-servers confirmation, partial failure with backoff then completion, last-attempt failure with the reason and a re-queue, supersede (only the latest state is pushed), idempotent trigger, **withdraw order proven** (`put-include` before `remove-zone`), withdraw waiting while the sites list still names the domain, withdraw never deleting a promoted contender's zone, website deployed/removed moving apex and entry, D7 switch, external DNS (no zone), adopt mode refusal then accept then relaxed, guard and switch, reconcile heal + unknown zones + invariants, the member's record API (locked platform records, refusals apply nothing, 404 for another member's claim) |
| **End to end on the real lab DNS** | `LabDnsPublishTest` (passed): a throwaway claim `e2e-<hex>.test2` is proven and published over ssh to the lab's BIND; `dig` on the server returns the SOA serial the Brain wrote, `www` CNAME, the `_afs-verify` TXT and the second name (`→ 10.0.0.244`, the parking host); the three standing lab tenants still resolve; no apex address before a website; the member edits records and `dig` returns the new serial, the A and the MX; a repeat publish leaves one stanza; the claim is released and the second name is dropped, the zone disappears from BIND (log: `zone … (primary) removed`), `named.conf` is clean, the file is in `.trash`, the standing tenants undisturbed |
| Serial | 1,000 random edits across midnight and month end: strictly increasing, no repeat, 32-bit |

**Defects the tests found and that were fixed:** the backfill re-attached an `orphaned` tenant on its second run (step 03); a rendered record with a TTL pushed `IN` out of alignment with the rest of the file (cosmetic, golden files regenerated);
the test for the retry backoff set its clock before the jobs existed.

**Not verified, honestly:**
- `include` mode and the `rebuild-conf` command against real BIND (tested only against the stand-in checkers).
- The sshpass fallback (§5), and **the Brain VM → DNS VM path**: the end-to-end ran from this PC with the lab key as `vagrant` + `sudo`. The lab's `_env` assumes the Brain runs it as root with `/root/.ssh/id_ed25519` (trusted on dns by `Setup-BrainTrust.ps1`); the code is not on the VMs, so that path was not exercised.
- Production: BIND's version and `named.conf` layout there, the agent install, the allowlist, and generated columns on its database engine.

## 7. What changed on the lab (everything is additive or backed up)

- **DNS VM `10.0.0.242`:** `/usr/local/bin/afs-dns-agent` installed; `/var/named/.staging` and `/var/named/.trash` created (`named:named`, 750). Before the end-to-end the zones and `named.conf` were copied to
  `/root/dns-backup-before-agent-e2e-20261008-201740/`.
- **`/var/named/zones/test2.include.zone` was re-rendered by the Brain** (the new header, `; afs-sites-serial: N` — now 215 after two end-to-end runs — and an explicit TTL of 300 on each line); the three records are the same names and addresses, and `sv5eaw74n4ln`, `s707ee8no009`, `su8oc4ii77dz` and `tenant1`–`3` still resolve.
  The old comment about `deploy-tenant.sh` is gone with the old header. **`brain/deploy-tenant.sh` and `dns/add-zone.sh` still append to that file; the Brain's next render does not know such a line and — after the first adoption — drops it.** Until they are retired (step 10), add any site to the database too.
- `test2.zone`'s serial was raised twice by the agent (now `2026100802`) because the include is `$INCLUDE`d. `/etc/named.conf.pre-afs` was saved by the agent's first stanza write; the two throwaway zones the end-to-end test created (`e2e-cbad1a.test2`, `e2e-7d6123.test2` — it ran twice) are gone from BIND and `named.conf` (their files are in `/var/named/.trash`). The agent was re-installed once after the first run to add one tidy-up (a removal also deletes the zone's rollback copy in `.staging`) and to document `named.conf.afs-prev`, the copy it leaves of the configuration a removal replaced.
- **Lab database** (`testdb_appfactorystudio`): step 03's migrations and backfill (03 completion report §7). No zone for any existing holder was queued.
- **`lab2/dns/afs-dns-agent`, `lab2/dns/install-agent.sh`, `lab2/provision/dns.sh`** (calls the installer) and `_env/lab2/.env` are repository changes; the **live** `.env` of the apps is not changed (apply with `use-env.sh lab2`, which is the user's act).

## 8. To do next, in order

1. **Put the code on the lab Brain and run it there:** sync the repository to the VMs, `use-env.sh lab2` (this turns publishing on), and confirm `php artisan schedule:list` shows `domains:publish` and `domains:dns-reconcile` and that whatever user runs `schedule:run` can read `DOMAINS_DNS_SSH_KEY`.
2. **Backfill the existing holders' first publish in the lab** when you want them served: `php artisan domains:backfill-tenants --apply --enqueue-publish` (adopt mode protects the include; the three standing tenants are already in it).
3. **Step 07 / 12** must call `DomainDnsSync::siteChanged($claimRef)` when a website goes live, moves or is removed. **Step 08** exposes `ZoneRecordService` on the signed surface and retires `json_patch` and the old zone write paths. **Step 05** (purchase) activates through `completePurchase`, which already publishes.
4. **Production runbook (step 10):** install the agent on each DNS server (`install-agent.sh`), set the allowlist to the real fleet, run `domains:backfill-claims`, `domains:backfill-tenants --dry-run`, then publish in adopt mode and resolve `--accept-drop` for the legacy include lines.
5. **Retire `deploy-tenant.sh` and `add-zone.sh`** as writers of the sites include (step 10), so the Brain is its only writer.
