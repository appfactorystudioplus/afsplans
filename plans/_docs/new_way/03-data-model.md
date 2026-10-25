# 03 — Data model (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/03-data-model.md](../domain_name_management/03-data-model.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The tenant, deployment and server tables are here; the claim, zone, site-reference, publish and event tables are in the domain-name half. A website is bound to one **active claim** (`claim_ref`), whose table is defined there.

Where the truth lives for a website, and the one index that makes "one live website per claim" a property of the database.

## 3.1 Ownership of each fact (one writer, one place)

| Fact | Lives in | Written by | Today (for contrast) |
|---|---|---|---|
| "This claim has a website on this server" | `brain_http_server_tenants` (extended) | Brain deploy service | tenant row + `members_websites` row + zone JSON flags |
| Member-facing website record, retention columns | `members_websites` (members app, shared DB) | Members, from the Brain's tenant row | independent row, independent reference |
| Deploy run history and step results | `brain_website_deployments`, `brain_deploy_steps` | Brain deploy runner | Redis + `laravel_website_deployment_progress` |

`members_websites.reference_num` equals `brain_http_server_tenants.reference_num` (already true for the newer flow,
feature 1 F1.3) and that stays the join. The zone JSON flags `has_website`, `site_enabled`, `alternate_*` are
**deprecated** — computed on read from the tenant row, never stored (F30, F31).

## 3.7 Websites: tenant, deployment, steps

`brain_http_server_tenants` (extended; existing columns stay)

| Column | Notes |
|---|---|
| `claim_ref` | The claim it serves |
| `site_ref` | Denormalised for joins |
| `server_ref` | The `brain_http_servers.reference_num` it lives on (today the join is by `public_ipv4`, which breaks the day two servers share an address behind NAT, or an IP changes) |
| `state` | `reserved` (capacity held, deploy not started; carries `reserved_until`) → `provisioning` → `active` → `offline` (suspended/retired) / `failed` / `removed` |
| `release_version` | The template commit SHA installed (07 §7.3) |
| `systemuser`, `db_name`, `db_user` | Typed, no longer only inside `json`. **No password here** — it is written once to the tenant's `.env` on the host and generated per tenant (F22); the Brain keeps no copy it does not need |
| `active_claim` | **generated, stored**: `CASE WHEN state NOT IN ('failed','removed') THEN claim_ref END` — **UNIQUE**. One live website per claim (feature 1, D6) |

`brain_website_deployments`

`id`, `ref`, `tenant_ref`, `claim_ref`, `kind` (`create`/`redeploy`/`move`/`remove`/`rollback`), `status`, `current_step`,
`steps_total`, `tracker_key` (the `ProgressService` key the UI polls), `server_ref`, `selection` (TEXT json: candidates,
scores, reasons — 06 §6.5), `release_version`, `client_key` **unique**,
`started_at`, `finished_at`, `error`, `resumable`. For `kind = move` also `from_server_ref`, `to_server_ref`, `reason`, `actor`, `hold_until`, `freeze_started_at`, `freeze_ended_at`, and a unique key on `tenant_ref` over non-terminal operations (one running deploy or move per tenant; step 12 §12.3).

`brain_deploy_steps` — `deployment_id`, `no`, `name`, `status` (`pending`/`running`/`done`/`failed`/`skipped`/
`compensated`), `attempts`, `started_at`, `finished_at`, `detail`, `compensated_at`. This is what makes a deploy
**resumable** and its clean-up exact.

`brain_http_servers` (typed columns for what selection queries; the JSON stays for the rest): `accepts_tenants`,
`draining`, `max_tenants` (null = derive from `tenant_size`/`ram_size`), `weight`, `region_ref`, `health_state`,
`last_heartbeat_at`, `agent_version`, `preflight_ok_at`, `preflight_detail`, `ssh_user`, `ssh_port`. The selection query in 06 reads these; no more parsing a
TEXT blob to pick a server.

## 3.8 `members_websites`

Keeps its columns and gains feature 1's retention columns. Two corrections: `domain_name_reference_num` must hold the
**claim reference**, not the name (the lab data had the name there); `tenant_reference_num` is NOT NULL once the tenant
row exists. A soft `active = 0` on delete is replaced by a real delete flow (07 §7.10).

> **§3.2–§3.6 (the claim table, site references, publish jobs, events, challenges) and the `brain_dns_subdomains` extension of §3.7** are in the domain-name plan: [../domain_name_management/03-data-model.md](../domain_name_management/03-data-model.md).

## 3.9 Configuration (not tables)

`config/domains.php` in the Brain, every value env-driven so lab2 and production differ only in `.env`:

| Key | Production | lab2 |
|---|---|---|
| `letsencrypt_check` (`LETSENCRYPT_CHECK`) | `true` | `false` (07 §7.6) |

(The other settings of this table are the domain-name plan's.)

## 3.10 Conventions this step follows

- Structured data in TEXT columns uses `Utils_encodeJson` / `Utils_decodeStringToJson`; **anything queried, joined or
  indexed is a typed column** (the reason selection and uniqueness are typed above).
- Brain tables are created by `www` migrations. `members_websites` changes are a `members` migration. Neither migration
  depends on the other app having run first (the schema-ownership rule in `w/AGENTS.md`).
- Every migration is additive; the unique indexes come last, after the backfill (step 10 §10.4).

## 3.11 Phases

| Phase | Work |
|---|---|
| **P0** | Tenant `claim_ref`/`state`/`active_claim`; `brain_website_deployments/steps` |
| **P1** | Typed server columns and migration of `tenant_size`/`ram_size` out of JSON; `members_websites` retention columns |
| **P2** | — |
