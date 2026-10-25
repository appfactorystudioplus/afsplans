# 03 — Data model: completion report (domain name management)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/03-data-model.complete.md](../../new_way/complete/03-data-model.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: built and tested (P0 and P1). P2 not built.** Carried out 2026-10-08 against [03-data-model.md](../03-data-model.md), on top of what step 02 already added
> ([02 completion report](02-domain-identity-and-collisions.complete.md) §3). Nothing is committed. The code is in `w/www` (the Brain), which owns the schema;
> `members` is untouched.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

The rest of the tables and columns the flow needs, and three more "only one" rules that are properties of the **database** instead of promises of the code:

| Rule | Enforced by |
|---|---|
| One **active** entry per `(zone, name)` in the sites list (the second name) | `UNIQUE (active_key)` on `brain_dns_subdomains` |

`UNIQUE (active_fqdn)` and `UNIQUE (member_live_key)` (one holder per name, one live claim per member per name) were built by step 02 and are unchanged.

| Plan section | Built as |
|---|---|
| §3.2 publish columns on `brain_dns_zones` | `desired_serial`, `published_serial`, `publish_state`, `registrar`, `registrar_ref`, `expires_at`, `auto_renew` |
| §3.4 `brain_dns_publish_jobs` / `_targets` | created, with the idempotency key and the per-server rows |
| §3.7 `brain_dns_subdomains` extended | `claim_ref`, `state`, generated `active_key` |
| §3.9 configuration | `config/domains.php` extended; `_env/lab2/.env` carries the lab values |

## 2. Files

All paths are under `C:\projects\vagrant\afs-eco\w\www` unless stated.

**Database** (`database/migrations/`)
- `2026_10_08_000003_add_publish_and_deploy_data_model.php` — everything above except the three unique indexes. Additive, every step guarded (safe to run twice, safe on a
  database that already has part of it), with a `down()`. It also makes two small corrections derivable from the row itself: a legacy `active = 0` subdomain row becomes
  `state = retired`, a legacy `active = 0` tenant becomes `state = removed`, so the new column never contradicts the old flag.
- `2026_10_08_000004_add_publish_and_deploy_unique_indexes.php` — the three unique indexes. **Refuses to run** while a duplicate exists, naming the duplicate values and the
  command to run, and applies nothing half way.

**Application**

- `config/domains.php` — the §3.9 keys (see §3.3 below).

**Tests** (`tests/Feature/Brain/Domains/Dns/DeployDataModelTest.php`; `tests/Concerns/CreatesDomainClaimTables.php` now builds the step-03 tables with the **real migrations**).

**Lab / environment** — `_env/lab2/.env` (the lab's `DOMAINS_*` keys for steps 03/04; the live `.env` was **not** touched — apply with `use-env.sh lab2`).

## 3. Data model as built

### 3.1 `brain_dns_zones` (the claim table)

Added: `desired_serial`, `published_serial` (`bigint unsigned`, null), `publish_state` (`none | pending | published | failed`, default `none`), `registrar`, `registrar_ref`,
`expires_at`, `auto_renew`. `desired_serial` is bumped in the same transaction as every change that must reach DNS (step 04); `published_serial` moves only when **every**
server confirmed it.

### 3.2 Publish outbox

- `brain_dns_publish_jobs` — `kind` (`zone | sites | named_conf | withdraw`), `zone_ref`, `fqdn`, `desired_serial`, `status` (`pending | running | done | failed | superseded`), `attempts`,
  `next_attempt_at`, `client_key` **UNIQUE**, timestamps, `result` (TEXT, `Utils_encodeJson`). Indexed for the worker (`status, next_attempt_at`).
- `brain_dns_publish_targets` — one row per `(job, DNS server)`, **UNIQUE (job_id, dns_server_ref)**: `status`, `serial_seen`, `error`.

### 3.3 The sites list (`brain_dns_subdomains`; the website and deploy records are in the website-deployment report)

- `brain_dns_subdomains` — `claim_ref`, `state` (`active | retired`), generated `active_key` = `domain_name|name` while active (**UNIQUE**).

### 3.4 Configuration (`config/domains.php`, all env-driven)

`sites_zone`, `platform_zone_parent`, `sites_ttl`, `hostmaster`, `soa{refresh,retry,expire,minimum}`, `default_ttl`, `parking_ipv4`, `publish_sites_on_activation`,
`publish_enabled` (**off by default**), `named_conf_mode`, `zone_dir`, `sites_include_file`, `sites_accept_drop`, `publish_max_attempts`, `publish_backoff_seconds`, `record_cap`,
`allowed_server_cidrs` (the guard), `dns_agent{…}` (ssh transport), `named_checkzone_binary`, `registrar_driver`, `registrar_env`, `letsencrypt_check`. The 02 keys are unchanged.
`registrar_*` and `letsencrypt_check` are defined now so the lab and production differ only in `.env`; nothing reads them until steps 05 and 07.

## 4. Where the build differs from the plan text

4\. **Partial unique indexes do not exist in MariaDB**, so the subdomains rule ("unique `(domain_name, name)` where `state = 'active'`") is a stored generated column
   (`active_key`) with a plain `UNIQUE` index, the same technique as step 02. The same is used for `open_tenant_key`.
5\. **Two migrations, not one** (the 02 pattern): a table that still holds duplicates cannot take a unique index, so the index migration waits for `domains:backfill-tenants --apply`.
6\. **`brain_dns_publish_jobs` has a `ref` column** (a short public reference for logs and the staff screen) and `client_key` is 120 characters (a hash plus the trigger).

## 5. What is not built (and why)

- **P2:** dropping the `active` / `own` mirrors and partitioning `brain_domain_events` — the plan marks both as later.
- **Production's engine has not been checked** for stored generated columns with a unique index (plan check C1 / D8). It is answered for the lab (MariaDB 10.5.29, below); run the same two statements on production before applying there. The side-table fallback of D8 is not needed on MariaDB 10.5.

## 6. Verification

Run against the real lab MariaDB (`10.0.0.243`, the throwaway `testdb_phpunit_www`; no SQLite).

| Result | |
|---|---|
| `DeployDataModelTest` | **9 tests, 83 assertions, all passing** |
| One active sites-list entry per name | a second active row is refused by `uq_brain_dns_subdomains_active_key`; retired duplicates coexist; a retired name can be listed again |
| Outbox idempotency | a repeated `client_key` and a repeated `(job, server)` target are refused by name |
| Index migration | with duplicates present it throws naming the value and `domains:backfill-tenants`, and nothing is added; after the duplicate is resolved it adds the index |
| Additive and repeatable | running the column migration again changes nothing; no password column exists on the tenant table |
| Legacy flags | a legacy `active = 0` subdomain / tenant becomes `retired` / `removed` when the state column is added |
| Migration guardrail | `MigrationOwnershipTest` (4 tests) still passes: every new table is prefixed, every index is named |
| Step 02 regression | all 44 step-02 tests still pass with the new migration in the harness |

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
| Migration `…000004` | applied: `uq_brain_http_server_tenants_active_claim`, `uq_brain_dns_subdomains_active_key`, `uq_brain_website_deployments_open_tenant` |

Rollback: `php artisan migrate:rollback --path=…` for the two files, or restore the `_backup_20261008` tables.

## 8. To do next, in order

1\. **Step 04's runtime on the lab**: sync the code to the VMs, `use-env.sh lab2`, make sure `schedule:run` runs on the Brain (the new commands are scheduled) and its user can read the ssh key (04 completion report §8).
3\. **Step 08**: route the old `members` write paths through the Brain so `domain_name_reference_num` and `tenant_reference_num` are written correctly, then make them NOT NULL.
4\. **Production**: check generated columns with a unique index on its engine (C1), run `domains:backfill-claims`, then `domains:backfill-tenants --dry-run`, resolve the orphan list with staff, `--apply`, and only then the index migration.
