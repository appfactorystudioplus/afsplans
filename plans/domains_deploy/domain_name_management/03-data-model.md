# 03 — Data model (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/03-data-model.md](../new_way/03-data-model.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The tables and settings of the domain side are here; the tenant, deployment and server tables are in the website-deployment half.

Where the truth lives, and the one index that makes "exactly one active claim per name" a property of the database
instead of a promise of the code.

## 3.1 Ownership of each fact (one writer, one place)

| Fact | Lives in | Written by | Today (for contrast) |
|---|---|---|---|
| A member's claim on a name, its state and proof | `brain_dns_zones` (extended) | Brain `DomainClaimService` only | `brain_dns_zones` + `own`/`active` flags + `has_website` etc. in its JSON |
| The zone's records and SOA | `brain_dns_zones.json_zone_data` (same shape as today) split into `system` and `member` record sets (04 §4.2) | Brain; member edits go through the API | one flat `records` array |
| The platform-owned second name | `brain_site_refs` (registry) + `brain_dns_subdomains` (the DNS record) | Brain at activation | minted at deploy, appended to a file |
| Publish run history | `brain_dns_publish_jobs`, `brain_dns_publish_targets` | Brain publisher | none |
| Why anything happened | `brain_domain_events` (append-only) | Brain | none |

## 3.2 `brain_dns_zones` — becomes the claim table

Existing columns stay (`reference_num`, `domain_name`, `member_reference_num`, `provider`, `provider_response`,
`tenant_reference_num`, `family`, `active`, `own`, `hostname`, `json`, `json_zone_files`, `json_zone_data`,
`website_reference_num`, `renewal_date`, `date`). **Added** (all nullable at first, so the migration is safe on a table
that has duplicates; step 10 §10.4 orders the backfill before the indexes):

| Column | Type | Meaning |
|---|---|---|
| `fqdn` | `varchar(253)` | Normalised ASCII key (02 §2.9). `domain_name` is kept as the display form |
| `kind` | `enum('member','platform')` | `platform` rows are the platform's own zones (`appfactory.studio`, the sites zone parent); never claimable |
| `origin` | `enum('purchased','connected')` | Replaces the overloaded `own` (F3). `own` is kept, written as `origin = purchased`, read by nothing new |
| `claim_status` | `varchar(24)` | The states in 02 §2.3 |
| `proof_level` | `enum('none','txt','registrar','staff')` | |
| `dns_mode` | `enum('managed','external')` | |
| `site_ref` | `varchar(32)` unique, null | Assigned at activation (§3.3) |
| `activated_at`, `verified_at`, `lapse_at`, `released_at` | `timestamp` null | |
| `last_checked_at`, `next_check_at` | `timestamp` null | Re-verification scheduler (02 §2.8) |
| `miss_count` | `tinyint` | Consecutive re-verification misses |
| `desired_serial`, `published_serial` | `bigint` | Zone SOA serial wanted vs last confirmed on every target |
| `publish_state` | `enum('none','pending','published','failed')` | Summary of the publish jobs (§3.4) |
| `registrar`, `registrar_ref`, `expires_at`, `auto_renew` | | Purchased names (05) |
| `active_fqdn` | **generated, stored**: `CASE WHEN claim_status IN ('active','suspended') THEN fqdn END` | **UNIQUE** |
| `member_live_key` | **generated, stored**: `CASE WHEN claim_status NOT IN (<terminal states>) THEN CONCAT(member_reference_num,'\|',fqdn) END` | **UNIQUE** — one live claim per member per name |

**The two unique indexes carry the design.**

- `UNIQUE (active_fqdn)`: SQL unique indexes ignore `NULL`s, so any number of non-active claims coexist while a second
  `active`/`suspended` row for a name is impossible. This is the line that answers "which domain is actually active": the
  row where `active_fqdn IS NOT NULL`.
- `UNIQUE (member_live_key)`: the same member cannot hold two live claims on a name (02 §2.9 rule 4).

Plus ordinary indexes: `(fqdn)`, `(claim_status, next_check_at)` for the scheduler, `(member_reference_num,
claim_status)` for the list.

> **To verify on lab2 before committing to it (step 10 P0):** stored generated columns with a unique index on the
> MariaDB/MySQL version lab2 runs, and the same on production's engine. If either cannot, the fallback is a one-column
> side table `brain_active_names(fqdn PRIMARY KEY, claim_ref)` written in the activation transaction — same guarantee,
> uglier. **D8.**

**Legacy mirror.** `active` (tinyint) is kept so existing readers (`listZones`, the staff screens, the Flutter Brain
app) keep working. It is maintained by the service in the same transaction: `active = 1` iff `claim_status IN
('active','suspended')`. Nothing new reads it. Removal is P2.

## 3.3 Site references — `brain_site_refs`

| Column | Notes |
|---|---|
| `site_ref` `varchar(32)` **primary key** | DNS-label-safe: lowercase letters and digits only, starts with a letter, e.g. `s` + 11 base-36 characters from a CSPRNG. 12 characters, ~10¹⁷ space; collision is handled by the PK, not hoped away |
| `state` | `reserved` → `active` → `retired` |
| `claim_ref` | The claim it was issued to |
| `issued_at`, `retired_at` | |

- Allocated **in the activation transaction** (02 §2.6). A collision is a duplicate-key error: retry with a new value.
- **Never reused.** `retired` rows stay forever (a `site_ref` is a tombstone, not a recyclable id). A dangling CNAME,
  bookmark or search-engine entry can therefore never resolve to a different member's site.
- `site_ref` is **not** the system user, the DB name or the DB user. Those are derived with a prefix and kept apart
  (07 §7.4), because a Linux user is limited to 32 characters and one identifier doing five jobs (F13) is how `x1s…`
  ended up in the DB name, the home directory and the DNS label at once.
- The sites name is `site_ref + '.' + SITES_ZONE` (`SITES_ZONE` = `sites.appfactory.studio` in production,
  `sites.test2` in lab2; one config key, 09 §9.2).

## 3.4 `brain_dns_publish_jobs` and `brain_dns_publish_targets`

The outbox for everything that must reach a name server.

`brain_dns_publish_jobs`

| Column | Notes |
|---|---|
| `id`, `ref` | |
| `kind` | `zone` (render and push one member zone), `sites` (the sites include), `named_conf` (rebuild the member-zones include), `withdraw` (remove a zone) |
| `zone_ref`, `fqdn` | The claim it concerns (null for `sites`/`named_conf`) |
| `desired_serial` | The serial this job must make live |
| `status` | `pending` → `running` → `done` / `failed` / `superseded` |
| `attempts`, `next_attempt_at` | Exponential backoff |
| `client_key` **unique** | Idempotency: the same trigger twice is one job |
| `created_at`, `started_at`, `finished_at`, `result` | |

`brain_dns_publish_targets` — one row per (job, DNS server)

| Column | Notes |
|---|---|
| `job_id`, `dns_server_ref` | |
| `status` | `pending`/`done`/`failed` |
| `serial_seen` | What `dig @server SOA` returned afterwards |
| `error`, `finished_at` | |

A job is `done` only when **every active DNS server in the zone's family** reports `serial_seen >= desired_serial`. A
newer job for the same zone marks older pending ones `superseded` (only the latest desired state matters — convergent,
not a replay log). A scheduled reconcile (04 §4.7) re-queues anything whose targets drifted.

## 3.5 `brain_domain_events` (append-only)

`id`, `claim_ref`, `fqdn`, `member_reference_num`, `event` (`claim_created`, `challenge_issued`, `verify_ok`,
`verify_miss`, `activated`, `contested`, `promoted`, `lapsed`, `released`, `superseded`, `purchase_*`, `publish_*`,
`site_ref_issued`, `site_deployed`, …), `from_status`, `to_status`, `detail` (TEXT, `Utils_encodeJson`), `actor`
(`system`, `member:<ref>`, `staff:<ref>`), `created_at`. Never updated, never deleted. This is the audit trail for
disputes and the source for "why is my domain pending" in the UI.

## 3.6 `brain_domain_challenges`

`id`, `claim_ref`, `kind` (`txt`), `host` (`_afs-verify.<fqdn>`), `token`, `status` (`open`/`passed`/`expired`),
`created_at`, `expires_at`, `last_checked_at`, `last_result` (TEXT json: servers asked, answers). One open challenge per
claim; re-issuing replaces it.

> **§3.7 (the websites: tenant, deployment, steps, typed server columns) and §3.8 (`members_websites`)** is in the website-deployment plan: [../new_way/03-data-model.md](../new_way/03-data-model.md).

## 3.7 `brain_dns_subdomains` (the sites list; the websites half of §3.7 is in the website-deployment plan)

`brain_dns_subdomains` (extended): add `claim_ref`, `state` (`active`/`retired`), and a unique key `(domain_name, name)`
where `state = 'active'`. For a sites name `domain_name` is the sites zone's parent record set; `name` is the
`site_ref`; `ipv4` is the hosting server (or the parking address before a site exists, 04 §4.5).

## 3.9 Configuration (not tables)

`config/domains.php` in the Brain, every value env-driven so lab2 and production differ only in `.env`:

| Key | Production | lab2 |
|---|---|---|
| `sites_zone` | `sites.appfactory.studio` | `sites.test2` |
| `platform_ns` | `ns1…ns4.appfactory.studio` | `ns1.test2` |
| `platform_zone_parent` | `appfactory.studio` | `test2` |
| `reserved_suffixes` | `appfactory.studio`, `appfactory.net`, … | `test2` (as a platform suffix, but customer lab names are allowed *under* a configured prefix, 09 §9.4) |
| `verify_resolvers` | public set | `10.0.0.242` |
| `extra_allowed_suffixes` | none | `test2` |
| `parking_ipv4` | the parking host | `10.0.0.244` |
| `registrar_driver` / `registrar_env` | `namecom` / `production` | `namecom` / `fake` |
| `allowed_server_cidrs` | the production fleet | `10.0.0.0/24` (the guard, 09 §9.1) |
| `named_conf_mode` | `stanza` | `stanza` (04 §4.4) |
| `zone_dir` | `/var/named/zones` | `/var/named/zones` (same layout as production) |
| `claim_cap`, `lapse_grace_days`, `unverified_expiry_days` | 20 / 14 / 14 | same |

## 3.10 Conventions this step follows

- Structured data in TEXT columns uses `Utils_encodeJson` / `Utils_decodeStringToJson`; **anything queried, joined or
  indexed is a typed column** (the reason selection and uniqueness are typed above).
- Brain tables are created by `www` migrations. `members_websites` changes are a `members` migration. Neither migration
  depends on the other app having run first (the schema-ownership rule in `w/AGENTS.md`).
- Every migration is additive; the unique indexes come last, after the backfill (step 10 §10.4).

## 3.11 Phases

| Phase | Work |
|---|---|
| **P0** | Columns on `brain_dns_zones` + both unique indexes (after backfill); `brain_site_refs`; `brain_domain_events`; `brain_domain_challenges`; `brain_dns_publish_jobs/targets`; the `brain_dns_subdomains` extension; `config/domains.php` |
| **P1** | Legacy flag removal from zone JSON |
| **P2** | Drop the `active`/`own` mirrors; partition `brain_domain_events` if it grows |
