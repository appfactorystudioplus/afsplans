# 03 — Data model: completion report (website deployment)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **website deployment** half; the **domain name management** half is [../../domain_name_management/complete/03-data-model.complete.md](../../domain_name_management/complete/03-data-model.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: built and tested (P0 and P1). P2 not built.** Carried out 2026-10-08 against [03-data-model.md](../03-data-model.md), on top of what step 02 already added
> ([02 completion report](../../domain_name_management/complete/02-domain-identity-and-collisions.complete.md) §3). Nothing is committed. The code is in `w/www` (the Brain), which owns the schema;
> `members` is untouched.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

The rest of the tables and columns the flow needs, and three more "only one" rules that are properties of the **database** instead of promises of the code:

| Rule | Enforced by |
|---|---|
| One **live website per claim** (feature 1, D6; audit F4 found three tenant rows for one name) | `UNIQUE (active_claim)` on `brain_http_server_tenants` |
| One **open operation per tenant** (a running deploy or move; 12 §12.3) | `UNIQUE (open_tenant_key)` on `brain_website_deployments` |

| Plan section | Built as |
|---|---|
| §3.7 `brain_http_server_tenants` | `claim_ref`, `site_ref`, `server_ref`, `state`, `reserved_until`, `release_version`, `db_name`, `db_user`, generated `active_claim` |
| §3.7 `brain_website_deployments`, `brain_deploy_steps` | created (move columns included) |
| §3.7 `brain_http_servers` typed columns (**P1**) | 12 typed columns added; `max_tenants` filled from the JSON |
| §3.8 `members_websites` corrections | done as a **data** correction by the backfill (see §4.1) |
| 10 §10.4 adoption of existing rows | `domains:backfill-tenants --dry-run / --apply` and the two-stage migration |

## 2. Files

All paths are under `C:\projects\vagrant\afs-eco\w\www` unless stated.

**Database** (`database/migrations/`)
- `2026_10_08_000003_add_publish_and_deploy_data_model.php` — everything above except the three unique indexes. Additive, every step guarded (safe to run twice, safe on a
  database that already has part of it), with a `down()`. It also makes two small corrections derivable from the row itself: a legacy `active = 0` subdomain row becomes
  `state = retired`, a legacy `active = 0` tenant becomes `state = removed`, so the new column never contradicts the old flag.
- `2026_10_08_000004_add_publish_and_deploy_unique_indexes.php` — the three unique indexes. **Refuses to run** while a duplicate exists, naming the duplicate values and the
  command to run, and applies nothing half way.

**Application**

- `app/Services/Brain/Domains/TenantState.php` — the tenant states and the list the generated column uses.
- `app/Services/Brain/Domains/DomainTenantBackfill.php`, `app/Console/Commands/DomainsBackfillTenants.php` — `php artisan domains:backfill-tenants`.

**Tests** (`tests/Feature/Brain/Domains/Dns/DeployDataModelTest.php`; `tests/Concerns/CreatesDomainClaimTables.php` now builds the step-03 tables with the **real migrations**).

**Lab / environment** — `_env/lab2/.env` (the lab's `DOMAINS_*` keys for steps 03/04; the live `.env` was **not** touched — apply with `use-env.sh lab2`).

## 3. Data model as built

### 3.3 Website and deploy records

- `brain_http_server_tenants` gains the columns above. `state` is `reserved | provisioning | active | offline | failed | removed | orphaned`. **No password column**: the tenant's
  database password lives only in its `.env` on the host (audit F22). `active_claim` = `claim_ref` while the state is not `failed`/`removed`/`orphaned` — `UNIQUE`, and NULL
  (so unconstrained) for a legacy row with no claim.
- `brain_website_deployments` — as the plan, plus the move columns (`from_server_ref`, `to_server_ref`, `reason`, `actor`, `hold_until`, `freeze_started_at`, `freeze_ended_at`).
  Statuses: `queued | running | paused | holding | done | failed | cancelled`; the first four are "open" and `open_tenant_key` (= `tenant_ref` while open) is `UNIQUE`.
- `brain_deploy_steps` — `UNIQUE (deployment_id, no)`; statuses as the plan.
- `brain_http_servers` — `accepts_tenants`, `draining`, `max_tenants`, `weight`, `region_ref`, `health_state`, `last_heartbeat_at`, `agent_version`, `preflight_ok_at`,
  `preflight_detail`, `ssh_user`, `ssh_port`. Selection (step 06) reads these instead of parsing the JSON.

(§3.1 the claim table, §3.2 the publish outbox, the `brain_dns_subdomains` bullet of §3.3 and §3.4 the configuration are in the domain-name report.)

## 4. Where the build differs from the plan text

1\. **`members_websites` is not a members migration.** The plan (§3.8, and the P1 row) says "a `members` migration". `w/AGENTS.md` §5 says **www owns every migration for the shared
   database; members ships none** — so there is none. What §3.8 asks for is built as a **data correction** in the backfill: for the website record of a tenant it attaches,
   `domain_name_reference_num` becomes the **claim reference** (the lab's rows held the name, or a random string nothing reads) and `tenant_reference_num` is filled. It is **not**
   made NOT NULL: the old write paths in `members` (`DomainController` lines 517, 619, 1241) still insert the name there until step 08 routes them through the Brain. The retention
   columns the P1 row mentions are **feature 1's** (`membership_change/features/1-member_domains_count.md`), which puts them on its own ledger table, not on `members_websites`; they
   were not built here.
2\. **The tenant state `orphaned` is new.** The audit's F4 rows (three tenants for one name) have to go somewhere that is neither a live website nor "removed" — nobody has said the
   site was deleted. The backfill keeps the one a member website points at (else the newest) and marks the others `orphaned`: kept, claim-less, reported for staff, excluded from
   `active_claim`. It is an addition to the plan's list of states.
3\. **`active_claim` also requires `claim_ref IS NOT NULL`** (the plan's expression would concatenate a NULL claim into a row that then looks live). Same effect, and a legacy tenant with no claim stays outside the constraint.
4\. **Partial unique indexes do not exist in MariaDB**, so the subdomains rule ("unique `(domain_name, name)` where `state = 'active'`") is a stored generated column
   (`active_key`) with a plain `UNIQUE` index, the same technique as step 02. The same is used for `open_tenant_key`.
5\. **Two migrations, not one** (the 02 pattern): a table that still holds duplicates cannot take a unique index, so the index migration waits for `domains:backfill-tenants --apply`.
7\. **`accepts_tenants` defaults to 1**, not 0 for `brain` as 09 §9.1 suggests for the lab: the lab hosts tenants on `brain` (09 D18a). Step 06 decides per server.
8\. **`max_tenants` is the only server column the backfill fills** (from `specs.tenant_size`). `ssh_user`, `ssh_port`, `region_ref` and the health columns are empty until steps 06/07 write them.

## 5. What is not built (and why)

- **No `members_websites` retention columns and no NOT NULL** (§4.1).
- **The rows nothing writes yet.** `brain_website_deployments` / `brain_deploy_steps` are empty until step 07 and `reserved`/`provisioning` tenant states until step 06: the schema is ready and tested, the writers are those steps.
- **Production's engine has not been checked** for stored generated columns with a unique index (plan check C1 / D8). It is answered for the lab (MariaDB 10.5.29, below); run the same two statements on production before applying there. The side-table fallback of D8 is not needed on MariaDB 10.5.

## 6. Verification

Run against the real lab MariaDB (`10.0.0.243`, the throwaway `testdb_phpunit_www`; no SQLite).

| Result | |
|---|---|
| `DeployDataModelTest` | **9 tests, 83 assertions, all passing** |
| One live website per claim | `provisioning`, `reserved`, `offline` rows for a claim that already has an `active` one are refused by `uq_brain_http_server_tenants_active_claim`; `failed`, `removed`, `orphaned` rows coexist; the slot is free once the website is `removed`; claim-less legacy rows are not constrained |
| One open operation per tenant | `queued`, `paused`, `holding`, `running` are refused by `uq_brain_website_deployments_open_tenant`; finished ones coexist |
| Index migration | with duplicates present it throws naming the value and `domains:backfill-tenants`, and nothing is added; after the duplicate is resolved it adds the index |
| Additive and repeatable | running the column migration again changes nothing; no password column exists on the tenant table |
| Legacy flags | a legacy `active = 0` subdomain / tenant becomes `retired` / `removed` when the state column is added |
| Backfill | keeps the tenant a member website points at, marks the duplicates `orphaned`, fills `claim_ref`/`site_ref`/`server_ref`, corrects the website record, fills `max_tenants`, and is **idempotent**; a dry run writes nothing; the first publish of every holder is queued only with `--enqueue-publish` |
| Migration guardrail | `MigrationOwnershipTest` (4 tests) still passes: every new table is prefixed, every index is named |

**A defect the tests found and that was fixed:** the first backfill selected `orphaned` rows again on the next run (they have no claim) and attached one of them — it was not
idempotent. Rows already in a released state are now excluded from selection.

**Not verified, honestly:**
- Production's database engine (§5).
- The old write paths (`members` `DomainController::store`, `BrainDnsController::saveZone`) still create zone rows and website records the old way; nothing new reads what they leave
  NULL, and the backfill is idempotent, so re-running it after old-style writes classifies them. Routing them through the services is step 08.

## 7. Applied to the lab database

Applied to the lab's real database (`testdb_appfactorystudio` on the brain VM) with only their own paths (`artisan migrate --path=…`), after copies of the four tables
(`brain_dns_subdomains_backup_20261008`, `brain_http_server_tenants_backup_20261008`, `brain_http_servers_backup_20261008`, `members_websites_backup_20261008`):

| Step | Result |
|---|---|
| Migration `…000003` | applied |
| `domains:backfill-tenants --dry-run` then `--apply` | 7 unattached tenant rows: **4 attached** (`laurensmenu.net`'s one kept tenant `j3UPsLOYBFrB`, and `tenant1`–`tenant3.10-0-0-243.sslip.io`, each with its sites label and `server_ref` of the brain server), **2 orphaned** (the other two `laurensmenu.net` tenants, `SjkQybVWTjdo`, `xKqxxUTVtWDD` — audit F4), **1 left alone** (`laurensmenu.com`: no claim holds that name) |
| Website records | the 4 attached tenants' `members_websites` rows now hold the **claim reference** in `domain_name_reference_num` |
| Servers | both `brain_http_servers` rows got `max_tenants = 200` |
| Migration `…000004` | applied: `uq_brain_http_server_tenants_active_claim`, `uq_brain_dns_subdomains_active_key`, `uq_brain_website_deployments_open_tenant` |

Two things to know: the lab's `laurensmenu.net` claim carries the sites label of tenant `SjkQybVWTjdo` while the kept tenant is the one the website record points at
(`j3UPsLOYBFrB`) — lab data from before claims, clean-up of the lab's old data is feature 1 D6; and `--enqueue-publish` was **not** used, so no zone for an existing holder was queued.

Rollback: `php artisan migrate:rollback --path=…` for the two files, or restore the `_backup_20261008` tables.

## 8. To do next, in order

2\. **Step 06 / 07** write `reserved` / `provisioning` tenant states, `server_ref`, `release_version` and the deployment records; step 07 must call `DomainDnsSync::siteChanged()` when a website goes live, moves or is removed.
3\. **Step 08**: route the old `members` write paths through the Brain so `domain_name_reference_num` and `tenant_reference_num` are written correctly, then make them NOT NULL.
4\. **Production**: check generated columns with a unique index on its engine (C1), run `domains:backfill-claims`, then `domains:backfill-tenants --dry-run`, resolve the orphan list with staff, `--apply`, and only then the index migration.
