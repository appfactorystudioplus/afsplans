# 16 — A member's own DNS records: a full editor, built on the database, rendered with Badcow

> **STATUS 2026-10-24: PLAN, then built the same day (P0, P1).** The completion report is [complete/16-member-dns-records.complete.md](complete/16-member-dns-records.complete.md). This step builds on [04](04-dns-zone-engine.md) §4.2 and §4.6 (what a member may put in a zone, and the calls that save it) and [08](08-brain-transport-api-and-ui.md) (the screens). The owner builds DNS records with the Badcow library (`w/www/vendor/badcow/dns`), so what a member can add is **limited to what Badcow can build**.

## 16.1 What exists and what is missing

A member can already list, add and delete records on the DNS tab, and the server already validates and publishes them (04 §4.6). The back end is ahead of the screen:

| | Server | Screen |
|---|---|---|
| Types | A, AAAA, CNAME, MX, TXT, NS, SRV, CAA | A, AAAA, CNAME, MX, TXT, NS |
| Edit a record | whole-set replace | delete and add again |
| TTL | 60 to 86400 | cannot be set |
| MX / SRV | any priority, weight, port | MX priority fixed at 10, SRV fixed at `0 0 443` |
| Hints | none | a free-text value box for every type |
| Two people saving at once | the second silently overwrites the first | the same |

And the zone text is written by hand (string formatting in `ZoneRenderer`), not built with Badcow.

## 16.2 Rules

1. **The database is the only thing a member changes.** A save writes the member's record set into `brain_dns_zones.json_zone_data`, bumps the serial and records a publish job. A zone file is written only by the publisher, which renders the zone from the database and sends it to the DNS server's agent (`afs-dns-agent`, which checks it with `named-checkzone` before swapping it in). No member call touches a zone file.
2. **Every record is built with Badcow's typed factories** (`Badcow\DNS\Rdata\Factory`), and the zone's records are written with `Badcow\DNS\AlignedBuilder`. A type is offered to members only if Badcow has a typed factory for it and the platform has a validation rule for it. Badcow's own text parser is *not* used to validate: it accepts nonsense (an SRV port of `x` becomes `0`), so the platform's rules (`ZoneRecords`) validate first and Badcow builds what passed.
3. **Types offered:** A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, TLSA, SSHFP, URI. Not offered: PTR, DNAME, NAPTR, HINFO, LOC and the DNSSEC types.
4. **The SOA block stays hand-written** in the form the DNS agent already reads (`<serial> ; serial`); Badcow builds the records after it.
5. **The platform's own records stay locked** (apex NS and A, `www`, `_afs-verify`, mail) and are still computed, never stored.

## 16.3 P0 — back end (`w/www`)

* `ZoneRecordRdata::make($type, $value)`: the canonical text value of a record to the Badcow `Rdata` for it (TLSA and SSHFP data are converted from hex to the bytes Badcow expects).
* `ZoneRenderer::render` builds the records with Badcow (platform records carry the comment `platform`); the output keeps the `$TTL`, the SOA block and the order of sections the agent and `ZoneValidator` read.
* `ZoneRecords`: TLSA, SSHFP and URI rules; a TXT value may not contain a backslash (Badcow would swallow it); `ZoneRecords::spec()` describes every type for the screen (its fields, labels and a hint).
* `GET zone` returns `types` (that description), so the screen's type list and forms always match what the server accepts.

## 16.4 P0 — the screen (`w/members`)

* An **Edit** button beside **Delete** on every member record.
* One form for add and edit: the type list and the fields come from `types`; MX has preference and host, SRV has priority, weight, port and target, CAA has flags, tag and value, TLSA and SSHFP and URI have their fields, and each field shows its hint.
* A **TTL** box (60 to 86400, empty for the default).

## 16.5 P1 — two people saving at once

`PUT records` takes an optional `expected_serial`. If the zone's desired serial has moved since the screen loaded, the save is refused with `DOM_E_ZONE_CHANGED` (409) and the screen reloads the list. Without `expected_serial` the save behaves as before.

## 16.6 Decisions and what is not in this step

| # | Question | Answer |
|---|---|---|
| D38 | Can a member's change write a zone file directly? | **No.** Database first, then the publisher (owner, 2026-10-24). |
| D39 | What can a member add? | What Badcow has a typed factory for and the platform has a rule for (16.2.3). |

Not in this step: PTR and reverse zones; DNSSEC; importing or exporting a zone file; editing several records and applying them once; per-record ids.
