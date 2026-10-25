# 02 — Domain identity and collisions: completion report

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

> **Status: built and tested (P0 and P1). P2 not built.** Carried out 2026-10-08 against
> [02-domain-identity-and-collisions.md](../02-domain-identity-and-collisions.md). Nothing is committed. The code is in `w/www` (the Brain),
> which owns the `brain_*` tables; `members` is untouched.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes
> the plan file.

## 1. What the step delivers

A claim on a domain name is **inert until it proves control**, the database allows **exactly one holder per name**, and every other claim
waits as `contested` and is promoted only if it can still prove control when the name is freed. That is the plan's §2.1 paragraph, built.

| Plan section | Built as |
|---|---|
| §2.3 states | `ClaimStatus` (`pending_verification`, `contested`, `purchasing`, `active`, `suspended`, `lapsed`, `released`, `superseded`, `expired_unverified`, `failed`) |
| §2.4 the TXT challenge | `DomainVerifier` + `DnsLookup` (interface) + `DigDnsLookup` (`dig`); challenge rows in `brain_domain_challenges` |
| §2.5 delegation is not identity | `VerifyResult::$delegated` is reported but never grants the name; tested (`test_delegation_to_the_platform_is_reachability_not_identity`) |
| §2.6 activation | `DomainClaimService::activate()` — one transaction, `FOR UPDATE` on every row for the name, unique index as the backstop |
| §2.7 precedence | contested claims never displace; `promote()` re-verifies the contender at promotion time; registrar purchase supersedes |
| §2.8 staying active | `DomainClaimService::sweep()` + `php artisan domains:reverify` (scheduled every 5 minutes) |
| §2.9 normalise and refuse | `DomainNameNormalizer`, `PublicSuffixes`; cap, rate limit, kill switch, uniform response |
| §2.10 what an unproven claim may do | nothing is published by this step; a site reference is issued only at activation |
| §2.11 races | row locks + `UNIQUE(active_fqdn)`; a real two-process test |
| Staff (§2.7, D3) | `contested()` list with evidence, `transfer()` (audited, reason required), `suspend()`/`unsuspend()` — **service methods only** (see §5) |
| 10 §10.4 adoption | `domains:backfill-claims --dry-run` / `--apply` and the two-stage migration |
| 03 §3.2, §3.3, §3.5, §3.6 | the part of the data model this step needs (see §3) |

## 2. Files

All paths are under `C:\projects\vagrant\afs-eco\w\www`.

**Application**
- `config/domains.php` — every setting (reserved names, extra suffixes, resolvers, caps, windows, kill switch); read only through `config()`.
- `app/Services/Brain/Domains/` — `ClaimStatus`, `DomainException`, `NormalizedName`, `PublicSuffixes`, `DomainNameNormalizer`, `DnsLookup`, `DigDnsLookup`,
  `VerifyResult`, `DomainVerifier`, `DomainEvents`, `SiteRefAllocator`, `DomainClaimService`, `DomainClaimBackfill`.
- `app/Console/Commands/DomainsReverify.php`, `DomainsBackfillClaims.php` — both do nothing unless `IS_BRAIN`.
- `app/Providers/AppServiceProvider.php` — binds `DnsLookup` to `DigDnsLookup`.
- `routes/console.php` — `domains:reverify` every five minutes, `withoutOverlapping`, `onOneServer`.

**Database** (`database/migrations/`)
- `2026_10_08_000001_add_domain_claim_columns_and_tables.php` — columns on `brain_dns_zones`, the two generated columns, `brain_site_refs`,
  `brain_domain_challenges`, `brain_domain_events`. Additive and guarded; has a `down()`.
- `2026_10_08_000002_add_domain_claim_unique_indexes.php` — `UNIQUE(active_fqdn)` and `UNIQUE(member_live_key)`. **Refuses to run** while any row has no
  `claim_status` or while duplicate holders remain, naming what to do.

**Tests** (`tests/`) — `Feature/Brain/Domains/{DomainNameNormalizerTest, DomainClaimsTest, DomainClaimBackfillTest, DomainActivationRaceTest, DigDnsLookupTest}.php`,
`Support/FakeDnsLookup.php`, `Support/domain_activate_worker.php`, `Concerns/CreatesDomainClaimTables.php` (builds the tables with the **real migrations**).

**Environment** — `_env/lab2/.env` gained the lab's `DOMAINS_*` keys (the live `.env` was not touched; apply with `use-env.sh lab2`).

## 3. Data model as built

`brain_dns_zones` gains: `fqdn`, `kind`, `origin`, `claim_status`, `proof_level` (`none|txt|registrar|staff|legacy`), `dns_mode`, `site_ref` (unique),
`activated_at`, `verified_at`, `lapse_at`, `released_at`, `last_checked_at`, `next_check_at`, `first_miss_at`, `miss_count`, and two **stored generated columns**:

- `active_fqdn` = `fqdn` when `claim_status IN ('active','suspended','lapsed')` else `NULL` — **UNIQUE**. The row where it is not `NULL` *is* "the active domain".
- `member_live_key` = `member|fqdn` while the claim is live — **UNIQUE**: one live claim per member per name.

Verified on MariaDB 10.5.29 (the lab's engine): generated columns, unique indexes ignoring `NULL`, and duplicate-key errors naming the index. **Plan check C1 is answered
for the lab; production's engine still needs the same check** before step 03 is applied there (decision D8's fallback — a side table — is not needed on MariaDB 10.5).

Not built here (belong to later steps): `desired_serial`, `published_serial`, `publish_state`, registrar columns (04, 05); the publish-job, deployment and tenant
columns/tables (03 remainder, 04, 07). The legacy `active` column is kept as a mirror (`1` while the claim holds the name).

## 4. Where the build differs from the plan text

1. **A `lapsed` claim holds the name until its grace ends.** The plan's table says `lapsed` does not count as active, but §2.8 keeps the zone published during
   grace and §2.7 promotes only after grace. Letting a contender in earlier would put two zones for one name on the DNS servers, so `lapsed` is in the
   holding set (and therefore in `UNIQUE(active_fqdn)`). A lapsed holder that proves control again within grace **recovers** with the same site reference.
2. **Unproven `pending_verification` claims are not moved to `contested` when someone else activates** (plan §2.6 step 3). `contested` is kept to mean "has a valid
   proof and is waiting", which is what staff, promotion and the member message rely on. A pending claim becomes contested when *it* proves.
3. **A third reason code, `token_mismatch`**, when the record at the domain is someone else's token. TXT is public DNS, so this reveals nothing secret; it tells a
   member who published an old token what is wrong. `token_missing` remains for "nothing found".
4. **Lapse timing:** a holder lapses when it has missed **three consecutive daily re-checks and 72 hours have passed since the first miss** (so the plan's
   "≥ 72 h, not three quick retries" holds with a daily schedule: the lapse lands on the fourth check). Configurable.
5. **Public suffix list.** The registrable-domain rule uses a built-in list (every single label, ~120 common multi-label public suffixes, popular private suffixes such as
   `github.io`) plus `DOMAINS_EXTRA_ALLOWED_SUFFIXES`, **not the full PSL**, because installing a package was not appropriate. The failure mode is a coverage gap
   (a name under an unlisted suffix accepted one label too deep), still gated by proof of control. The interface is `PublicSuffixes::registrable()`; swap the list, not the callers.
6. **Reserved names are checked before the subdomain rule**, so `foo.sites.appfactory.studio` is told "belongs to the platform", not "add the main domain".
7. **Two migrations, not one** (the plan's §10.4 order made concrete): the unique indexes cannot be added to a table that still holds the old duplicates.
8. **Legacy holders (D5(a)):** a grandfathered holder is never asked for a TXT until someone contests the name; then a challenge is created for it and it is
   re-checked like any other. Failing that eventually lapses it — the cost of D5(a) that the plan accepted.

## 5. What is not built (and why)

- **No HTTP routes.** The signed Brain surface and the members pass-through are **step 08**. The staff views (`contested()`, `transfer()`, `suspend()`) are service methods
  ready to be exposed there.
- **No registrar.** `beginPurchase()` / `completePurchase()` / `failPurchase()` implement the name lock and the registrar proof; the saga, payment and registrar adapter are **step 05**.
- **No notification mail** to a superseded or displaced holder (P2 in the plan).
- **No subdomain claims** (D4(a) refuses them in v1) and **no registrar-side ownership sync** for connected names (P2).
- **No publishing.** Activation issues the site reference and sets `dns_mode`; writing the zone to BIND is **step 04**.
- **The old write paths still bypass this.** `members` `DomainController::store()` and the Brain's `BrainDnsController::saveZone()` still insert zone rows the old way
  (verified: an old-style insert succeeds and leaves `claim_status` `NULL`). Such rows are not covered by the unique indexes until they are classified; re-running
  `domains:backfill-claims --apply` classifies them (it is idempotent). Routing those paths through `DomainClaimService` is step 08.

## 6. Verification

Run **once at the end** (per the project's working rules), against the real lab MariaDB (`10.0.0.243`, the throwaway `testdb_phpunit_www`; no SQLite):

| Result | |
|---|---|
| New suite, final run | **44 tests, 297 assertions, all passing** (`tests/Feature/Brain/Domains`) |
| Real concurrency | `DomainActivationRaceTest`: two separate PHP processes, two database connections, released at the same instant, 6 rounds — every round exactly one `active` and one `contested`, one holder, one site reference issued |
| Database as the backstop | a second holder and a second live claim by one member are refused by the unique indexes (asserted by index name) |
| Coverage of the plan's matrix | §2.12 cases 1–10 (below) |

§2.12 matrix → tests: 1 `test_first_to_prove_wins…`; 2 `test_reverse_timing…`; 3 `test_release_promotes…`; 4 `test_delegation_to_the_platform_is_reachability_not_identity`;
5 `test_a_purchase_locks_the_name_and_supersedes_unproven_claims`; 6 `test_a_claim_is_inert_idempotent…`; 7 `DomainNameNormalizerTest` + the same-claim assertion; 8 the refusal tests;
9 `DomainActivationRaceTest`; 10 `test_a_holder_that_stops_proving_lapses…` (with `Carbon::setTestNow`).

**Defects the tests found and that were fixed:** the backfill lost the sites label already in DNS for a live website (a by-reference map captured by value in an arrow
function — the label would have been replaced by a new one); a test helper named `status()` collided with PHPUnit; one expectation about the verdict for a delegated domain
was wrong (corrected to `token_mismatch`, see §4.3).

**Not verified, honestly:**
- `DigDnsLookup` is tested with Laravel's process fake (the command line it builds, how `dig` output is read, unreachable → `null`, a hostile name stays one argument). It was **not**
  run end to end from the application. `dig` and PHP `intl` exist on the Brain VM, and `dig` there reads the lab DNS's `_afs-verify` TXT and NS correctly, but the new code is not on the VMs (they
  run the host machine's copy of the repository).
- Older tests were not used as a regression check: `BrainStaffInfraJobTest` fails 9 of 12 against the throwaway database because it lacks tables those tests assume (e.g. `subdomain_properties`), and
  `BrainStaffInfraDnsWriteTest` has a fatal error of its own (a private `put()` clashing with Laravel's test base class). Neither file was touched. The application boots, `php artisan list` shows both
  commands and `schedule:list` shows `domains:reverify`.
- Production's database engine has not been checked for generated columns with a unique index (plan check C1).

## 7. Applied to the lab database

The two migrations were run on the lab's real database (`testdb_appfactorystudio` on the brain VM) with only their own paths (`artisan migrate --path=…`), after a copy of the table
(`brain_dns_zones_backup_20261008`). `domains:backfill-claims --dry-run` showed no conflicts, then `--apply`:

| id | name | result |
|---|---|---|
| 25, 26 | `laurensmenu.net`, `laurensmenu.restaurant` | `active`, proof `registrar` (purchased with a registrar response); `laurensmenu.net` **kept its existing sites label** `x1sfmakl3ajie3f` |
| 27 | `hello.com` | `active`, proof `legacy` |
| 32–34 | `tenant1`–`tenant3.10-0-0-243.sslip.io` | `active`, proof `legacy`, **kept their sites labels** `sv5eaw74n4ln`, `s707ee8no009`, `su8oc4ii77dz` — the ones already in the lab DNS |

6 site references registered, 6 events, three unique indexes in place (`uq_brain_dns_zones_active_fqdn`, `uq_brain_dns_zones_member_live_key`, `uq_brain_dns_zones_site_ref`).
Rollback: `php artisan migrate:rollback --path=…` for the two files, or restore from the backup table.

## 8. To do next, in order

1. **Sync the code to the lab VMs** (it is only on this machine), apply `use-env.sh lab2` for the new `DOMAINS_*` keys, and confirm `domains:reverify` runs on the Brain.
2. **Step 03** (the rest of the data model) and **step 04** (publish the zone; its `_afs-verify` platform record is what makes managed domains re-verify against the platform's own servers).
3. **Step 08** (the signed routes) to put `members` on `DomainClaimService` and retire the old insert paths — until then re-run the backfill after old-style writes.
4. **Step 05** for purchase; D8 on production's engine; the full public suffix list when a package may be installed.
