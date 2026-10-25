# 16 — A member's own DNS records: completion report

**Plan file:** [`../16-member-dns-records.md`](../16-member-dns-records.md)
**Date:** 2026-10-24
**Status:** ✅ P0 and P1 built and tested. Not seen in a browser and not published to the dev server's DNS by a member edit. Nothing is committed or uploaded.

## 1. What was built

### Back end (`w/www`, `app/Services/Brain/Domains/Dns/`)

| Plan section | Built as |
|---|---|
| §16.2 Badcow builds every record | New `ZoneRecordRdata::make($type, $value)`: the canonical text value to the Badcow `Rdata` through its typed factories (`Factory::A`, `AAAA`, `CNAME`, `NS`, `MX`, `TXT`, `SRV`, `CAA`, `TLSA`, `SSHFP`, `URI`); TLSA and SSHFP hex is converted to the bytes Badcow expects. A type with no case there throws. |
| §16.2 / §16.3 renderer | `ZoneRenderer::render` builds the zone's records with Badcow (`Zone`, `ResourceRecord`, `AlignedBuilder`) for the platform's records (`platformRdata`, the same set as `platformRecords`, which stays the text shown locked to the member) and the member's. The `$TTL` line, the SOA block (`<serial> ; serial`, the form `afs-dns-agent` reads) and the header comment stay hand-written; Badcow's `$ORIGIN` and `$TTL` lines are dropped. A record Badcow cannot build is left out and reported in `dropped`. The old hand-written `row`, `rdata` and `target` are gone. |
| §16.2 types | `ZoneRecords::TYPES` = A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, **TLSA, SSHFP, URI**, each with a rule in `recordProblem` (TLSA usage 0-3, selector 0-1, matching 0-2, even hex; SSHFP algorithm 1-4, type 1-2, even hex; URI priority, weight and a target without quotes or control characters). **A TXT value may not contain a backslash** (Badcow drops it). |
| §16.3 the form description | `ZoneRecords::spec()` returns `ttl` (60 to 86400) and, per type, its fields in the order they make up the value (key, label, number/text/select, required, min/max, options, a hint). `GET zone` (`ZoneRecordService::zone`) carries it as `types` and `ttl`. |
| §16.5 stale saves | `PUT records` takes `expected_serial` (`DomainsSurfaceController` validates `nullable\|integer`); `ZoneRecordService::replaceRecords(..., ?int $expectedSerial)` compares it with the zone's `desired_serial` inside the locked transaction and refuses a mismatch with `DOM_E_ZONE_CHANGED` (409), saving nothing. No serial means the old behaviour. |
| Unchanged on purpose | `ZoneValidator` (its line parser reads the new format as it is), `named-checkzone` on the agent, the publisher and `afs-dns-agent` (nothing to redeploy to the DNS server). |

### Members app (`w/members`)

* `DomainSurfaceController::records` forwards `expected_serial` (a null one is dropped).
* `surfaceTypes.ts`: `ZoneFieldSpec`, `ZoneTypeSpec`, `SurfaceZone.types` and `.ttl`. `surface.ts`: `saveRecords(claimRef, records, expectedSerial)`.
* `surfaceViews.ts` `dnsView`: an **Edit** button beside **Delete**; one add/edit form whose type list and fields are drawn from `types` (MX preference and host, SRV priority, weight, port and target, CAA flags, tag and value, TLSA, SSHFP, URI), each field with its hint; a **TTL** box checked against the range; a stored value is split back into the fields of its type for editing; on `DOM_E_ZONE_CHANGED` the member is told and the list is reloaded.
* `domain_dns.html`: the type list, the content box and the title are now filled by that code; a fields container and a TTL box were added.
* Labels and hints come from the server in English; the few fixed texts the screen adds use the existing translation keys when present and an English fallback otherwise (new keys were not added to `locales.json`).

### Documentation

`16-member-dns-records.md` (the plan, written first), this report, a README row. `complete/00_extras.md` E23.

## 2. Verified

* **Brain, on a local MariaDB:** `tests/Feature/Brain/Domains` **287 tests, 0 failures**, 21 skipped (they need real VMs). New: `MemberDnsRecordsTest` (5 tests): every member type has a sample that passes its rule and builds with Badcow, an unknown type throws; the form description lists exactly `ZoneRecords::TYPES` and each type's fields join into a value the rules accept; the new types are written by Badcow and the whole zone passes `ZoneValidator::structure`; a backslash in TXT is refused; a save from an old serial is refused with 409 and changes nothing, and one from the current serial works, and `zone()` carries `types` and `ttl`. The four golden zone files were regenerated and the diff read; `ZoneRendererTest` and the long-TXT assertion were adapted (Badcow writes a long TXT as several short strings in parentheses).
* **Members:** `DomainSurfaceControllerTest` (one new test: the serial is forwarded and the member comes from the token), `DomainRoutesTest`, `DomainBrainSurfaceTest`: **31 tests**, OK. `vitest` for the domains screens: 8 passed.
* **TypeScript:** `tsc` in `w/www`: 0 errors. In `w/members`: 62 errors, the same 62 as before, none in `controllers/domains/`.

## 3. Mistakes found on the way

* The first renderer put `; platform` comments on the platform records; they sit flush against the value (`10.0.0.243; platform`) and broke a test that reads the line. Removed.
* My first `tsc` run found indexed array accesses the project's strict settings reject (`member[idx]`, `types[0]`); fixed with explicit checks. An earlier file of mine (`brain_domains.ts`) used optional chaining, which the project avoids; replaced with an explicit check.
* Badcow's text parser accepts nonsense (an SRV port `x` becomes `0`, a bare TXT is cut at the first space), so it is **not** used to validate; the platform's own rules validate first and Badcow only builds. This is stated in the plan (§16.2).

## 4. Not done, and why

1. **Not seen in a browser.** The form compiles and type-checks and its pieces are tested, but no one has clicked through it.
2. **No member edit has been published to the real DNS server** from this screen; the dev server was not touched and nothing is uploaded. The publisher and agent are unchanged, and the new zone format passes the structure check and (in the golden tests) `named-checkzone` only where BIND's tools exist, which they do not on this PC.
3. **The old DNS screen code** (`dns.ts`) is still in the repository, now unreachable; it still names the removed `dns_record_content` box.
4. **Not in this step:** PTR and reverse zones, DNSSEC, zone-file import and export, applying several changes at once, per-record ids (all listed in the plan §16.6).
