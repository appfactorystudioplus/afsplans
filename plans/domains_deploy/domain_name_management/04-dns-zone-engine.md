# 04 — DNS zone engine: from claim to BIND

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

How an active claim becomes a zone file on the DNS servers, how the second name (`<site_ref>.sites.<zone>`) is created
and listed, and how records stay correct when a site moves. Replaces the stubs (F7), the append (F8) and the
member-zone push that no code calls (F9).

## 4.1 Parts

```
 members UI ──signed──▶ Brain API ──▶ DomainClaimService ──▶ (DB: zone JSON, desired_serial, publish job)  ◀── one transaction
                                                                       │ outbox
                                                                       ▼
                                          DnsPublisher (queued worker, per-zone lock)
                                          ├─ ZoneRenderer   pure function: claim + records + tenant state → zone text (golden-file tested)
                                          ├─ ZoneValidator  PHP rules + `named-checkzone` where available
                                          └─ DnsTransport   ssh (key first, password fallback) → afs-dns-agent on each DNS server
                                                                       ▼
 DNS server:  afs-dns-agent put-zone | remove-zone | rebuild-conf | status
              <zone_dir>/<fqdn>.zone     zone stanza in /etc/named.conf (or member-zones.conf, §4.4)     rndc reload / reconfig
```

Nothing writes a zone, a zone stanza or an include except `DnsPublisher`. No `echo … >>`, no `systemctl reload named`, no expect script
that copies a whole directory to a nameserver.

## 4.2 The zone model: system records versus member records

The stored shape stays the one the UI and `InfraDnsRules` already read —
`{domain, default_ttl, soa, nameservers, include, records[{name,type,value,ttl}]}` — with one addition: every record
carries `managed_by`: `platform` or `member`. `platform` records are **not stored**; they are **computed at render time**
from the claim and the tenant, so they can never go stale and a server move needs no edit of the member's data.

| Record | Source | Notes |
|---|---|---|
| `SOA` | system | `mname` = first platform NS, `rname` = platform hostmaster, `serial` = `desired_serial` (§4.3) |
| `NS` (apex) | system | All platform nameservers (fixes the empty default, F11) |
| `@ A` | system | The tenant's server IPv4 when a site is active; **omitted** otherwise (D7: parking on the apex is a product choice) |
| `www CNAME` | system | `<site_ref>.sites.<zone>.` — one record to change when a site moves |
| `_afs-verify TXT` | system | The claim's token, kept published for the life of a managed active claim (see below) |
| `MX` and mail host | system, P1 | Only when the email module is on for the domain; points at the platform mail host, which has an A record (fixes F11). Owned by `membership_change/features/2-domain_email_accounts.md`; this plan only reserves the slot |
| anything else | member | Entered in the DNS tab, stored in `json_zone_data.records` |

**Rules the API enforces on member records** (§4.6): in-zone names only; legal types (`A AAAA CNAME MX TXT NS SRV CAA`);
no CNAME at the apex or next to another record; no record at a name a system record owns (`@ A`, `www`, `_afs-verify`);
TTL between 60 and 86400; at most 500 records; value syntax per type. A member cannot delete or edit a platform record;
the UI shows them locked with a "managed by the platform" label.

**Why the TXT stays published.** After a member moves NS to us, the public DNS for `_afs-verify.<fqdn>` is *our* zone;
the token they put at their old provider no longer answers. Re-verification (02 §2.8) therefore asks our authoritative
servers, and the platform record is what they answer with. This is safe for the incumbent and still useless to a
challenger, who cannot write into the incumbent's zone (02 §2.5). If a member later moves NS **away** from us the
platform record disappears with the zone, `dns_mode` flips to `external`, and they must publish the token at the new
provider; the UI says exactly that.

## 4.3 The serial

`desired_serial` comes from `InfraDnsRules::nextSerial($stored)` — `YYYYMMDD00` or one more than the stored value,
strictly increasing, also within a day. It is bumped **in the same transaction** as every change that must reach DNS
(a member record edit, a site deployed, moved or removed, activation, lapse), never by the UI (F10). The publisher writes
that serial and confirms it on each server before it counts a job as done.

## 4.4 Publishing a zone — the algorithm

Run by a queue worker, one job per zone, under `GET_LOCK('dnspub:<fqdn>')` (02 §2.11).

1. **Re-read** the claim `FOR UPDATE SHARE`. If the job is `zone` and the claim is not `active`/`suspended`, convert to
   `withdraw` (or drop if never published). A job never publishes on behalf of a claim that has lost the name.
2. **Render** the zone text from the claim, member records, tenant state (§4.2) and `desired_serial`.
3. **Validate** locally: PHP checks (single SOA, NS present, no apex CNAME, no duplicate CNAME) and, if the Brain host
   has BIND utilities, `named-checkzone <fqdn> <file>`. A validation failure marks the job `failed` with the message
   and sets `publish_state = failed`; nothing is sent.
4. **For each active DNS server in the zone's `family`** (in parallel, bounded), through `afs-dns-agent`:
   1. `put-zone <fqdn> --serial N` with the zone text on stdin → agent writes `/var/named/zones/.staging/<fqdn>.<N>`;
   2. agent runs `named-checkzone`; on error it leaves the live file untouched and exits non-zero;
   3. agent `install`s the file to `<zone_dir>/<fqdn>.zone` (`named:named`, `0640`) — an atomic rename. `zone_dir` is `/var/named/zones`
      in production **and in the lab** (09 §9.5); the stanza says `file "zones/<fqdn>.zone";`;
   4. the agent makes sure the DNS server **knows the zone** (`named_conf_mode`, below), runs `named-checkconf`, then `rndc reconfig`
      for a new zone or `rndc reload <fqdn>` for a changed one;
   5. agent answers `dig @127.0.0.1 SOA <fqdn> +short`; the Brain stores `serial_seen` on the target row.
5. **Complete** when every target has `serial_seen >= desired_serial`: `published_serial = desired_serial`,
   `publish_state = published`, event `publish_ok`. A partial result keeps the job `running`/`failed` with per-server
   detail and retries with backoff; the zone stays served by the servers that succeeded.
6. **Supersede:** a newer pending job for the same zone makes older ones `superseded`. Only the latest desired state is
   ever pushed.

**How the server learns about a zone (`named_conf_mode`).**

- **`stanza` (default, and what the owner wants):** the agent appends one marked block to **`/etc/named.conf`** — the same shape as the
  existing `zone "test2"` block, which is also what production's old `move_zone_and_reload` appended:

  ```
  // afs-zone: tenant1.10-0-0-243.sslip.io
  zone "tenant1.10-0-0-243.sslip.io" IN {
      type master;
      file "zones/tenant1.10-0-0-243.sslip.io.zone";
      allow-update { none; };
  };
  ```

  It is idempotent (a block that already exists is left alone), the original `named.conf` is copied once to `named.conf.pre-afs`, and a
  withdraw removes exactly the block between its marker and the closing `};`. This is what `lab2/dns/add-zone.sh` does today (09 §9.11).
- **`include`:** the agent regenerates one `member-zones.conf` from the database and `named.conf` includes it. Cleaner to converge, and
  an option for a server whose `named.conf` should not be edited; not the default.

Either way the Brain's database is the source of truth: the reconcile (§4.7) lists the zones a server has against the claims that should
be there, and reports (does not silently delete) a zone the database does not know. Zone files the Brain no longer lists are moved to
`<zone_dir>/.trash/` (timestamped), never deleted.

**One-time bootstrap per DNS server** (installed by provisioning, not by a deploy): the agent script; in `include` mode the `include
"/var/named/member-zones.conf";` line (in `stanza` mode nothing is added to `named.conf` in advance); `rndc` control channel enabled on
the loopback (already working on the lab DNS VM); the staging/trash directories. In lab2 this is added to `provision/dns.sh` (09 §9.5). In production it is a runbook step written in step 10.

## 4.5 The second name

**Created at activation** (02 §2.6), not at deploy (F13):

1. `brain_site_refs` row (state `active`).
2. `brain_dns_subdomains` row: `domain_name = SITES_ZONE`, `name = site_ref`, `ipv4 = parking_ipv4`, `state = active`,
   `claim_ref`. This **is** "applied to the appfactory.studio subdomains list": the row is the list entry.
3. A `sites` publish job.

**The sites include is rendered, not appended.** The `sites` job renders every active `brain_dns_subdomains` row for
`SITES_ZONE` into the include file and pushes it with the same stage → check → rename → reload sequence
(`appfactory.studio.include.zone` in production, `/var/named/tenants.zone` in lab2 — configured per environment):

```
$ORIGIN sites.appfactory.studio.
$TTL 300
s4k2m9q1x7p  IN A 45.79.42.137   ; claim R7QK2 · example.com
```

TTL 300 so a server move propagates in minutes. The comment carries the claim reference and name for support.

**Before a site exists** the record points at `parking_ipv4`, a host that answers any `*.sites.<zone>` name with a "this
domain is set up, no website yet" page. In lab2 that is the `web` host's default vhost (09). If the owner prefers not to
publish before a deploy, flip `domains.publish_sites_on_activation` to false: the row and reference still exist at
activation and the record appears at first deploy (**D7**).

**After deploy** the row's `ipv4` becomes the server's address; after a move it changes again; on release/lapse the row
goes to `retired`, the record disappears at the next render, and the `site_ref` is a tombstone forever (03 §3.3).

**Option noted, not chosen:** a wildcard `*.sites IN A <edge>` with a routing layer in front (the repository already has
an HAProxy build under `core/scripts/docker/haproxy`). It removes per-site DNS writes but adds a proxy tier that every site
depends on; it conflicts with the explicit requirement that each name is "applied to the subdomains list". **D9.**

**Dynamic update option (P2):** the sites zone is a good candidate for RFC 2136 `nsupdate` with a TSIG key — no file
rewrite and no reload per site — and the same mechanism is what automated DNS-01 for the wildcard certificate needs (07
§7.6).

## 4.6 Member-facing DNS API (Brain, called by members over the signed surface)

| Call | Behaviour |
|---|---|
| `GET /domains/{claim}/zone` | System records (read-only) + member records + `publish_state`, `published_serial`, last publish error |
| `PUT /domains/{claim}/records` | Replace the member record set; server validates (§4.2), bumps `desired_serial`, enqueues a publish, returns the new state. Replaces the browser sending the whole zone object (`saveDomainZone`) |
| `POST /domains/{claim}/records/import` (P2) | Probe the domain's current public DNS for common names and offer them as member records — connected domains that move NS lose their existing site/mail unless the records are copied |

The generic `PATCH /api/v1/domains/{id}` with `json_patch` (arbitrary keys merged into zone JSON, including the
`has_website`/`site_enabled`/`alternate_*` flags) is **removed** in favour of these calls plus the website calls in 07/08.
A client that can merge arbitrary keys into the zone document can set `site_enabled`, `has_website` or `provider`, which other parts of the system read as facts.

## 4.7 Reconcile and drift

A scheduled command, every 10 minutes:

- For every active managed claim: `dig @<each server> SOA` against `published_serial`; mismatch ⇒ enqueue a publish.
- For `sites`: compare the include's serial to the rendered one.
- **Invariants (reported, not auto-fixed in P0):** exactly one active claim per name; every active claim has a `site_ref`
  and a `brain_dns_subdomains` row; every active tenant's `@ A` equals its server IP; no zone file on a server that the
  database does not list.
- Output goes to the staff infra screen (`StaffInfraController`) and to `brain_domain_events` for the affected claim.

## 4.8 Withdrawal

When a claim stops holding the name (`lapsed` past grace, `released`, `superseded`, deleted): a `withdraw` job re-reads
the claim, removes the zone from the servers (to `.trash`) and its stanza (or regenerates `member-zones.conf`), retires the `site_ref`, removes
the sites record, and (07 §7.10) takes the website offline first. The order matters: **site offline → sites record gone →
zone withdrawn**, so a name never resolves to a vhost that no longer belongs to it.

## 4.9 Multiple DNS servers

P0 follows the existing model: push to every active `brain_dns_servers` row in the zone's `family`. The production
estate has several (`ns1…ns4`); the lab has one. P2: one primary receives the push and secondaries pull with
`also-notify` + `allow-transfer` under a TSIG key (the live `afsdata/servers/live/named/slaves` directory shows the
shape), cutting the push fan-out and the number of credentials held.

## 4.10 Testing this step

- **Golden files:** render fixtures covering a bare claim, a claim with a site, member records of every type, the legacy
  record shape; compare to checked-in expected text modelled on `afsdata/servers/live/named/zones/*.zone`.
- **`named-checkzone`** over every golden file in CI (where BIND tools exist) — never trust the renderer alone.
- **Serial:** property test, strictly increasing across 1,000 random edits including midnight roll-over.
- **Publisher against a fake transport** (records the commands); then **against lab2 `dns`** end to end: add a claim,
  see `dig @10.0.0.242 example.test2 SOA` return the new serial, edit a record, see it change, withdraw, see NXDOMAIN.
- **Convergence:** delete a zone stanza from `/etc/named.conf` (or corrupt `member-zones.conf`) on the lab server, run reconcile + publish, confirm it heals.

## 4.11 Phases

| Phase | Work |
|---|---|
| **P0** | `ZoneRenderer`, validator, `DnsPublisher` with the stage/check/rename/reload/verify sequence, `afs-dns-agent`, zone stanza management in `/etc/named.conf` (and the `include` mode), sites include render, activation hook, record API with the §4.2 rules, lab2 bootstrap of the DNS VM |
| **P1** | Reconcile + invariants, withdrawal, mail slot, parking host, retries/backoff UI, password-transport fallback for production servers, runbook for the production bootstrap |
| **P2** | Record import, primary/secondary with TSIG, RFC 2136 for the sites zone, DNS-01 hook for the wildcard certificate |
