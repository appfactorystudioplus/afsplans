# 13 — Managing the Brain's infrastructure

## Completion document

> **Monitoring and controlling the servers themselves** (what they are running, their health, live network, CPU and RAM, what is using them, restarting services) is a separate, larger piece of work, planned in [14](14-server-monitoring-foundation.md), [15](15-server-live-and-attribution.md) and [16](16-server-control.md). This file manages the servers as *records*; those files add what the machines are *doing*.

**Complete in code 2026-10-05 (P10 to P16), with two deliberate exceptions: DNS apply is not built and starting a transfer is switched off by default. Not verified against a database, a lab or a device.** The completion document is `complete/13-brain-infrastructure.complete.md`; it holds the verified state, the divergences and the lab checks. The table below is the per-phase status as built.

| Phase | State |
|-------|-------|
| **P10** | **Built, not verified on a device or against a live server.** Server: BS13 (the group, the resource registry, the exact-encoding reader, the version hash, the `brain_infra_audit` and `brain_infra_history` migration) and the read routes of BS14 to BS21, with `whoami` capabilities `infra`, `infra_config`, `infra_http`, `infra_tenants`, `infra_dns` (and `infra_dns_apply` and `infra_jobs` reported `false`). App: the **Infra destination**, the hub with warnings and load bars, a list and a Form / JSON / History document screen for every resource, the config Sections view, and the **JSON viewer** (text and tree, find, wrap, copy) with the parse, duplicate-key, print and diff core the editor will use. 956 tests, all gates green. The server PHPUnit file (`BrainStaffInfraReadTest`) lints and its encoding class was exercised directly, but **it has not been run against a database**. |
| **P11** | **Built, not verified on a device or against a live server.** Server: `InfraDiff`, `InfraValidator`, `InfraWarnings`, `InfraWriter` and `StaffInfraWriteController`; routes `POST /http/{servers,tenants,clusters,regions}`, `PUT /http/{...}/{ref}`, `POST /http/{...}/{ref}/retire`, `DELETE /http/{...}/{ref}`, and the generic `POST /history/{id}/rollback`; `whoami` capability `infra_write`; no new migration (the P10 audit and history tables are used). Every write takes `document`, `expected_version`, `reason` (3 to 500), `acknowledged` and `dry_run`; a stale version is a 409 and is never acknowledged away; a real write needs every warning code the dry run listed. App: the **editable JSON editor** (text with live validation, line and column, key toolbar, undo and redo, format and minify, find and replace, go to error; tree with typed leaf edits, add key or item, delete, copy), the **Form** (one typed field per top-level column, generated from the document so nothing is hidden), the **review** screen (the server's own changes with old and new values, warnings as checkboxes with details, reason, typed confirm on production), Edit, Retire, Delete, Add and Roll back, and lost-answer settling by re-reading, never re-sending. 1030 tests, all gates green. **`BrainStaffInfraWriteTest` has never been run against a database.** |
| **P12** | **Built, not verified.** Server: `InfraConfigRules` (errors for addresses, the allow-list, duplicate key names, the https redirect; warnings `access_list_changed`, `mail_provider_position`, `consumer_secret_cleared`, `embedded_credential_stale`, `unknown_key`), `sync_embedded_urls`, `PUT /config/{ref}`. App: the Sections editor, critical sections typed on production, **Sync repo URLs**. |
| **P13** | **Built, not verified.** Server: `InfraDnsRules` (both zone shapes read and kept; the serial raised strictly and shown in the review; `zone_invalid`, `zone_serial_not_increased`, `zone_record_only`, `last_dns_server_in_family`, `named_conf_edit`) and writes for DNS servers, zones, subdomains and named.conf. App: the zone Records editor, legacy conversion, templates. **Saving changes the database only.** |
| **P14** | **Built, with two exceptions.** Server: `InfraTransfer`, `InfraJobRunner`, `StaffInfraJobController` (transfer, jobs, a job in full); `infra_jobs`, `infra_transfer`. App: the transfer flow, the job screen, a tenant's transfers. **Starting a transfer is off until `BRAIN_INFRA_TRANSFER=true`; DNS apply (BS23) is not built and `infra_dns_apply` is `false`** (finding F8). |
| **P15** | **Built.** A client of `/v1/brain/apps`: no server change. |
| **P16** | **Built.** A client of `/v1/brain/storage`: no server change. |

1158 app tests, all gates green. Five server PHPUnit files and the updated whoami test have **never been run** (no database on the authoring PC).

What P10 settled that the plan left open: the **config row's JSON tab shows the whole row** (the inner document plus the row's own `id`, `reference_num` and `date`); **a row with no `reference_num` is addressed as `id:<n>`**; **zoneless server timestamps are read as UTC**; and the legacy-route exclusion test and the `G1` gate were adjusted (the gate's `appfactory` pattern is now `appfactory.`, because `appfactory_github` is a real config key and not a host).

**Everything is shown in full, secrets included, and everything is editable as JSON** (§13.2, §13.6). Phases: read-only console **P10**; fleet writes **P11**; config writes **P12**; DNS writes **P13**; long jobs (tenant transfer, DNS apply) **P14**;
Apps **P15** (this is 12's optional P9, moved here); Storage fleet **P16**. Server work is **BS13 to BS25** (§13.5), in `w/www`, all behind the
staff group. Background: the Brain's own tables and screens as they exist today, analysed below.

**This file reverses part of [09](09-other-brain-sections.md).** 09 §9.3 to §9.6 left DNS, the HTTP servers and tenants, the Apps control plane and the
storage fleet to the browser, because the routes that manage them were the weakest on the Brain and a mis-tap could take websites offline. The
request is now that the app manages **all** of the Brain's resources. So the question this file answers is not "should it", it is **"what has to
exist on the server first so that it is safe from a phone"**. The answer is the safety model in §13.3 and the server work in §13.5.

## 13.1 What exists today

### The resources

| Resource | Table | Rows | Where the data really lives | Web screen |
|----------|-------|------|-----------------------------|------------|
| **Brain config** | `brain_config` | **one** (`reference_num` `default`) | the `json` text column: every credential, git host, mail provider and default A record the Brain uses | Brain → Controller (a JSON-schema form), one big Save |
| **HTTP servers** | `brain_http_servers` | one per server | columns for identity and addresses; `json` for logins, specs, ports | Brain → HTTP (a JSON form per server) |
| **Tenants** | `brain_http_server_tenants` | one per website deployment | columns for identity; `json` for domains, certificate paths, **database credentials** | Brain → HTTP → Tenants (list, detail, transfer) |
| **Server clusters** | `brain_server_clusters` | head + proxy pairs | `head`, `proxy` columns; `json` (clones) | Brain → HTTP → Clusters |
| **Regions** | `brain_http_servers_regions` | named regions | `name`, `location` | Brain → HTTP |
| **DNS servers** | `brain_dns_servers` | name servers, grouped by `family` | columns, **including `username` and `password` in plain columns** | Brain → DNS |
| **DNS zones** | `brain_dns_zones` | one per managed domain | `json_zone_data` (the records), `json_zone_files` (generated), `provider_response` | Brain → DNS → a zone |
| **named.conf** | `brain_dns_named` | one row the legacy save always writes as `id = 1` | `json` | Brain → DNS (an Ace editor) |
| **Subdomain A records** | `brain_dns_subdomains` | tenant vanity names | columns | Brain → DNS |
| **Apps** (settings, mail events, history) | `brain_apps*` | per app | `ManifestService` and friends | Brain → Apps |
| **Storage fleet** (servers, devices, keys) | `brain_storage_*` | per server and device | registry services | Brain → Storage |

All the JSON columns are **text, not the MySQL JSON type**, stored as `htmlentities(json_encode(…))` and read back with `html_entity_decode` then
`json_decode` (`FunctionsController::Utils_encodeJson` / `Utils_decodeStringToJson`). Any new server code must read and write that exact
encoding, or the legacy readers (the provisioning scripts, the browser) will mis-parse what it writes.

None of these tables has an `updated_at`; they have one `date` column set at creation. There is **no way today to tell that a row changed under you.**

### The JSON documents

The example files (`resources/js/portal/_examples/_files/json_format/brain_config.json` and `brain_http.json`) give the shapes. They **contain what look
like live credentials** (root and database passwords, git and registrar tokens, a mail API key). This plan uses their *shape* only and never
reproduces a value. Whether any of them should be rotated is your call; the example folder is in a repository.

**`brain_config.json`.** Top level: `mysql_id`, `category`, `nameid`, `domain_name`, a legacy `keys[]` (a flat copy of the production keys), `controller`, `default_server`.
Under `controller` (secret paths marked **S**):

| Path | Content | Who reads it |
|------|---------|--------------|
| `reference_num`, `brain_user`, `brain_host`, `domain_registrar` | the Brain host and the registrar's name | the transfer job (as `brain.*`) |
| `brain_pass` **S**, `mysql_password` **S**, `portal_root_password` **S**, `portal_admin_password` **S** | the Brain host login and database/portal passwords | the transfer job copies **all four** into a file on disk |
| `portal_ip_login[]` | IPv4 addresses | **no PHP in `www` reads it** (it may gate something in a script or another app; unknown) |
| `appfactory_github.key` **S**; `github_webnetpages{user, token S, repo, org S}` | GitHub access | tenant packaging |
| `gitea_3050{…}` and `gitea_3051{…}`, each: `url`, `api_url`, `prefix`, `postfix`, `domain`, `afspackage_repo` **S**, `members_repo` **S**, and `one` / `two` / `three` `{gitea_token S, gitea_username, gitea_password S}` | the two git hosts | `gitea_3051` is **referenced 11 times** (the tenant git-settings builder used by provisioning and transfer) |
| `keys[]{name, production{client, secret S, token S, username, token_name}, development{…}}` | maxmind, google, namecom | domain registration, geo |
| `integrations.hubspot{client_id, client_secret S, redirect_uri, scope}` | an OAuth app | the HubSpot callback |
| `email_providers[]{provider, identifier, token S, secret S, user, zone}` | the **system mail** providers | **by position: `email_providers[0].token`**, in six references: billing and dunning mail, the mail handler, the MUA, SendGrid domain auth, the integration sender |
| `default_server{at, www, www15}` each `{name, ip, comment}` | the default A records for a new zone | the default-subdomain seeding |

Two things in that document will bite an editor that is not careful: the **same credential is stored in up to three places** (a Gitea token appears in
`one.gitea_token` *and* inside the `afspackage_repo` and `members_repo` URLs, which embed `user:token@`), so changing one leaves the others stale; and
**`email_providers` is read by index**, so reordering or deleting the first entry silently switches or breaks every system email.

**`brain_http.json`** is one `brain_http_servers` row: `id`, `active`, `reference_num`, `country`, `server_type`, `server_class`, `public_ipv4`,
`private_ipv4`, `public_ipv6`, `private_ipv6`, `domain_name`, `hostname`, `date`, `region`, `provider`, and `json`:
`password` **S**, `users[]{username, password S}`, `specs{tenant_size, ram_size, cpu_size}`, `ports{blocks_range, full_range{start, end}}`, and a `tenants[]`
array. The admin form constrains `server_type` to Brain, Enterprise, Proxy, Head, Clone, Keepalive, Backup, Standby; `server_class` to Paid or Free; the
country to a short list. **`json.tenants[]` duplicates the tenants table** and is not kept in step with it (the transfer code and the web delete guard
disagree about which is the truth); the app treats the table as the truth and `json.tenants[]` as legacy.

**Tenants** (`brain_http_server_tenants`): columns `active`, `domain_name`, `public_ipv4`, `subdomain`, `reference_num`, `systemuser`,
`member_reference_num`, `package_version`, `json`; the `json` carries `Domain`, `Domain2`, `Address`, `Address2`, four certificate paths, `SystemUser`,
`Username`, `Subdomain`, `CreatedBy`, `member_reference_num`, `db_username`, **`db_password`**, `db_name`, `db_host`, `owner`, `server`, and port blocks.
**A tenant belongs to a server only by `public_ipv4` string equality**: there is no foreign key, so editing a server's IP orphans its tenants.

**DNS zone data** has **two shapes**. The legacy one is `{origin, ttl, soa, ns[], a[], aaaa[], cname[], mx[], txt[], srv[]}`; the one the browser now writes
(it normalises on save) is `{domain, default_ttl, soa, nameservers[], include[], records[{name, type, value, ttl}]}`. Existing rows can be either. The
SOA `serial` is only set when a zone is first created; **nothing bumps it on edit**, which means a changed zone is not seen by secondary name servers.

### The routes, and who is allowed to call them

| Routes (`/portal/api/v1/brain/…`) | Middleware | Notes |
|-----------------------------------|------------|-------|
| `config` (GET, POST), `dns/*`, `http/*`, `member*`, `logs`, `subdomain_properties` | **`portal.admin.auth` only** | `portal.level99` was switched off on 2026-08-29 because other apps and server-side callers reach these without a level-99 user (`routes/portal.php`). The member and allocation routes were re-protected on 2026-09-23; **these were not** |
| `apps/*`, `storage/*` | `brain.only`, `portal.admin.auth`, `portal.level99` | the modern staff groups: attributed to the staff member, history and rollback (Apps), no delete route and a secret shown once (Storage) |
| `staff/*` | the same three | where the phone app's routes live |

### What the existing controllers do, read line by line

These are findings, not opinions; each is a reason for a rule in §13.3.

| # | Finding | Where |
|---|---------|-------|
| F1 | `GET config` returns the **entire** config, every secret in clear, to **any logged-in portal user**. `POST config` replaces the **whole document** from the request with **no version check**: the last writer wins, and a partial or stale document silently erases the rest | `BrainController::getConfig/saveConfig` |
| F2 | The admin page itself embeds the whole config into the HTML (`view('portal.admin.index', ['brain' => $brainconfig])`) | `routes/portal.php` `/admin` |
| F3 | `POST http/tenants/move` **returns the Brain's whole config and the full transfer payload in its HTTP response** (root and database passwords, git tokens) and **writes the same payload to a JSON file** under `/mnt/node1/…/tmp` before starting a background PHP process through a shell string | `BrainHttpServerController::moveTenants` |
| F4 | `moveTenants` gathers its inputs by calling the Brain's *own* routes over HTTP **with no credentials** (`getTenantData`). Those routes require `portal.admin.auth`, so by reading, the calls get 401 and the payload is built from empty arrays (`$data['zones'][0]` is undefined). **Not run; this needs confirming on the lab before anyone relies on transfer** | same |
| F5 | `PATCH http/tenants/{id}` assigns **every key in the request** to the model (`foreach ($request->except(['_token']) as $key => $value) $tenant->$key = $value`): identity fields, `id` and `date` included | `updateTenant` |
| F6 | `DELETE http/servers/{ref}` and `DELETE dns/servers/{ref}` delete the row **with no check** for tenants, clusters or zones that depend on it. The browser's guard reads `json.tenants[]`, which is not the truth (see above) | both `deleteServer` |
| F7 | There are **no update routes** for HTTP servers, regions, clusters, DNS servers or subdomains. The browser edits them through the **legacy `save_brain_settings`** (a generic table writer in `FunctionsController` that builds SQL from the request) | `Utilities.BrainSave` |
| F8 | `POST dns/reload-named`, `reload-subs`, `reload-zone`, `build-reload-named` are **stubs that return "queued" and do nothing.** The browser calls them after every DNS save. The legacy implementations (`reload_named2`, `build_and_reload_named`, `RunCommand`, which `exec`s a script on the host) are not routed. **By reading, nothing in the Laravel API currently pushes a DNS change to the name servers.** Not run; needs confirming | `BrainDnsController`, `FunctionsController` |
| F9 | `POST dns/zones` (`saveZone`) returns the raw database row and the model's attributes in its response, and `GET dns/zones` returns every zone with its generated zone files (a medium-text column each) | `BrainDnsController` |
| F10 | `GET http/servers/available`, the provisioning picker, chooses the server with the fewest tenants **regardless of `server_type`, `active` capacity or `specs.tenant_size`**: a Standby, Backup or Clone server can be picked | `getAvailableServer` |
| F11 | `createServer` validates a short field list and the rest is dropped: `private_ipv4` and `server_class` sent by the form never reach the row. `BrainHttpServerTenant::$fillable` lacks `package_version`, so it cannot be written through the model | `createServer`, the model |
| F12 | `adminAddTenant` creates a tenant row **without provisioning anything**: no system user, no database, no certificate, no DNS | `adminAddTenant` |
| F13 | `RecordProgress` (`POST /progress/record`, `GET /progress/{key}`) is unauthenticated, keyed by a random 16-character tracker key | the progress routes |

## 13.2 What the app does with each resource

**Two rules cover everything below.** (1) **Nothing is hidden:** every resource is shown in full, exactly as stored: every column and the whole decoded `json`, secrets
included, unknown keys included, legacy fields included. There are no masked values, no placeholders and no "reveal" step. (2) **Everything is editable as JSON:** every resource has
a **Form** view for the common fields and a **JSON** view that is the whole document in an editor ([§13.6](#136-the-app)); what you save is the whole document.

| Resource | See | Edit | Create | Retire | Delete (record only) | Notes |
|----------|:---:|:----:|:------:|:------:|:--------------------:|-------|
| **Brain config** | ✅ the whole document | ✅ form by section **and** the whole JSON | — (one row) | — | — | history and rollback of every save |
| **HTTP servers** | ✅ the full row | ✅ form and JSON | ✅ | ✅ `active = 0` | ✅ | dependency list shown first (§13.3 rule 6) |
| **Tenants** | ✅ the full row, database credentials included | ✅ form and JSON, **identity fields too** (acknowledged) | ✅ **a record only: it provisions nothing** (F12) | ✅ | ✅ record only: it does **not** remove the website, database or DNS | transfer is a job |
| **Tenant transfer** | ✅ status, **payload and log** | — | starts a job | — | — | P14 |
| **Clusters** | ✅ | ✅ form and JSON | ✅ | ✅ | ✅ | |
| **Regions** | ✅ | ✅ form and JSON | ✅ | ✅ | ✅ | |
| **DNS servers** | ✅ the full row, password included | ✅ form and JSON | ✅ | ✅ | ✅ | |
| **DNS zones** | ✅ the full row: `json_zone_data`, `json_zone_files`, `provider_response` | ✅ form (records by type) and JSON | ✅ | ✅ | ✅ record only | the SOA serial is bumped on a save unless the JSON itself changes it |
| **Subdomain A records** | ✅ | ✅ form and JSON | ✅ | ✅ | ✅ | |
| **named.conf** | ✅ | ✅ **JSON and a text view** | — | — | — | acknowledged warning; **apply is a separate, deliberate step** |
| **DNS apply** | ✅ status, payload and log | — | starts a job | — | — | P14 |
| **Apps** | ✅ | ✅ settings, mail, definitions; the manifest as JSON | ✅ | — | the existing routes' own rules | the existing routes, unchanged |
| **Storage fleet** | ✅ the registry; a server's credential **as the existing route returns it** (create and rotate) | ✅ | ✅ | ✅ retire | — | the existing routes, unchanged (no delete exists; none is added) |

What the editor will **not** let you change, because it is not an edit but a different row: **`id` and `date`** of any row. They are shown; the server ignores a change to them and tells you it
did. Everything else is yours to change, with the checks in §13.3.

## 13.3 The safety model

The data is **yours and fully visible**. These rules protect the *system* from a mistake, not you from the data. The server enforces them; the app tells you first.

1. **A staff surface, not the legacy routes.** All new routes live in `api/v1/brain/staff/infra/*` with `brain.only`, `portal.admin.auth` and `portal.level99`. **The app never calls the legacy
   `config`, `dns/*` or `http/*` routes.** They keep working for the browser and the server-side callers that need them; closing them (F1, F5, F6) is a separate decision
   ([OQ-I3](#139-open-questions)) and is not part of this file.
2. **Nothing is hidden, and the data is handled accordingly.** Responses carry `Cache-Control: no-store`. In the app the infrastructure screens' text is **never logged** (the redactor is extended to
   these routes' bodies and to the config's secret paths), **never written to disk, the list cache or the clipboard history by the app** (copy is a deliberate tap), and, in a production build, these
   screens are `FLAG_SECURE` (no screenshots, no recents thumbnail; file 11). The app lock (file 10) applies. None of this hides a value from you; it stops it leaking from the phone.
3. **Every write carries the version it was based on, and a stale write is never forced.** `version` is a hash of the stored text, computed on read and compared on write (no migration; it also changes when a
   **legacy writer** changes the row). A mismatch is `409 BS_E_STALE_RECORD`; nothing is written; the app offers **Reload** and **Review changes** against the new copy, as the member profile does.
4. **You save a whole document, and see exactly what changed first.** Both the Form and the JSON view submit the full new document with `expected_version`. The server diffs it against the stored one and
   answers `dry_run` with: the **changed paths** (added, changed, removed, with old and new values), the **validation errors**, and the **warnings**. The app shows that as the review step. Nothing is written until
   you confirm it.
5. **Validation is split into errors and warnings.** *Errors* mean the text is not an acceptable document and nothing can be saved: it is not valid JSON; the root is not an object; a known field has the wrong
   type; an address field is not an address; `id` or `date` was changed. *Warnings* mean "this is allowed, here is what it does", and each has a **code**; a save that triggers warnings is refused until the request
   lists those codes in `acknowledged`, which the app does with a typed confirmation. **Unknown keys are preserved, never dropped**, and listed as a warning (`unknown_key`) so a typo is visible.
6. **The warnings the server raises** (this is the "force", and it is per code, audited, and never applies to rule 3):

   | Code | Raised when |
   |------|-------------|
   | `server_has_tenants` | retiring, deleting, or changing the `public_ipv4` of a server that active tenants match; the **tenant list** is returned |
   | `server_in_cluster` / `server_is_default` | the server is a cluster head or proxy, or owns a `default_server` address |
   | `region_in_use` | a server uses the region |
   | `last_dns_server_in_family` | retiring or deleting the last active DNS server of a family |
   | `tenant_identity_changed` | a tenant's `reference_num`, `public_ipv4`, `subdomain`, `systemuser`, `member_reference_num` or `domain_name` changed: the website, its database and its DNS are **not** moved or renamed |
   | `tenant_record_only` | creating or deleting a tenant row: nothing is provisioned or removed |
   | `mail_provider_position` | `email_providers[0]` was changed, removed, or moved: **six** places read it by position |
   | `consumer_secret_cleared` | a secret a consumer reads (the first mail token, `gitea_3051.one.gitea_token`) was emptied |
   | `embedded_credential_stale` | a git token or username changed and the same credential embedded in `afspackage_repo` / `members_repo` / `org` no longer matches; `sync_embedded_urls: true` rewrites them |
   | `access_list_changed` | `portal_ip_login` was emptied or lost an address ([OQ-I4](#139-open-questions)) |
   | `zone_invalid` | the zone has no SOA, fewer than two NS, a CNAME at the apex or beside other records, or a record that does not parse: **that zone will not load** |
   | `zone_serial_not_increased` | the SOA serial in the document is not greater than the stored one (the server bumps it for you unless you changed it on purpose) |
   | `named_conf_edit` | any change to named.conf: **every zone depends on it** |
   | `unknown_key` | a key the schema does not know |
7. **History and rollback for every JSON resource.** Every write first copies the previous row (columns and text) into `brain_infra_history` (resource, reference, actor, device, reason, time, the full previous
   document). The app lists a resource's history with **values**, diffs any two versions, and **rolls back** to one (a typed confirmation on production). A rollback is itself snapshotted, so it can be undone.
8. **Delete is real, record-only, and listed first.** Deleting asks for a typed confirmation after showing what depends on the row and the sentence "this removes the record only". It is an
   acknowledged-warning flow like any other; the row is snapshotted into history first, so a delete can be **restored** from history.
9. **One writer at a time.** Writes take a per-resource lock (`Cache::lock`); a transfer takes a lock on the tenant **and** on the target server; a second request while one runs is `423 BS_E_BUSY`.
10. **Long operations are jobs.** A transfer or a DNS apply returns a `tracker_key` and runs in the background through the existing progress service; the app polls `GET staff/infra/jobs/{key}`
    (authenticated). The job's detail shows **everything**: the steps, the messages, the exact payload it was started with and the log. The payload file is written `0600` and removed when the job
    ends. The polling survives backgrounding; a job whose answer was lost is found again from the tenant's or zone's own status, never started twice.
11. **An audit row for every write.** `brain_infra_audit` (resource, reference, action, changed paths, acknowledged codes, reason, actor, device, time), written in the same transaction as the change;
    if the audit write fails the change is **not** made. (The values are in history, rule 7.)
12. **A reason on every write** (3 to 500 characters), and a **typed confirmation on production** for: any acknowledged warning, delete, rollback, starting a transfer, DNS apply, and any save to a
    config section marked `critical` (§13.4). The typed text is the resource's own name or address.
13. **`dry_run` does not lie.** It returns exactly what a real save would return, and writes nothing.

## 13.4 The config: sections and the whole document

The config has two ways in. The **Whole document** screen is the JSON view of the entire `brain_config` row. The **section screens** are forms over the same document, so you can edit a git host without
scrolling a 170-line file; both save through the same request (§13.3 rule 4). A section's `critical` flag means "a save here is typed-confirmed on production"; it does **not** mean "not editable".

| Section id | Content | `critical` | What the server warns about |
|------------|---------|:----------:|------------------------------|
| `identity` | `domain_name`, `nameid`, `category`, `mysql_id` | | `unknown_key` |
| `host` | `controller.brain_host`, `brain_user`, `brain_pass`, `mysql_password`, `portal_root_password`, `portal_admin_password`, `domain_registrar`, `reference_num` | ✅ | `consumer_secret_cleared`; `brain_host` must be an address (error) |
| `access` | `controller.portal_ip_login[]` | ✅ | `access_list_changed`; each entry must be an IPv4 (error) |
| `git_github` | `appfactory_github`, `github_webnetpages` | ✅ | `embedded_credential_stale` (the `org` URL embeds user and token) |
| `git_gitea_3050` / `git_gitea_3051` | the host fields, the three token and password sets, the two repo URLs | ✅ | `embedded_credential_stale`, `consumer_secret_cleared`; the form's **Sync repo URLs** sets `sync_embedded_urls` |
| `keys` | `controller.keys[]`, edited by **`name`**, production and development sets | ✅ for `namecom` | a duplicate `name` is an error |
| `integrations` | `hubspot` | | `redirect_uri` must be an `https` URL (error) |
| `email_providers` | `controller.email_providers[]`, edited by **`identifier`** in the form | ✅ | `mail_provider_position`, `consumer_secret_cleared` |
| `default_server` | `at`, `www`, `www15`: name, ip, comment | | each `ip` an IPv4 (error); the screen shows which HTTP server owns each address |
| `legacy_keys` | the top-level `keys[]` copy | | shown and editable so nothing is "missing" |

## 13.5 Server additions (BS13 to BS25)

All in the group `api/v1/brain/staff/infra`, all returning the BS-codes error shape of [04](04-server-additions.md), all behind §13.3. `whoami` gains the capabilities `infra` (any of the below),
`infra_config`, `infra_http`, `infra_tenants`, `infra_dns`, `infra_dns_apply`, `infra_jobs` (each true when its routes are registered, like the existing ones). `apps` and `storage` already exist.

Every resource has the same four routes, so the app has one editing flow: **`GET /{resource}/{ref}`** (the full row, decoded, with `version`), **`PUT /{resource}/{ref}`** (the whole document, `expected_version`,
`reason`, `acknowledged[]`, `dry_run`), **`POST /{resource}`** (create, same body without a version) and **`DELETE /{resource}/{ref}`** (`expected_version`, `reason`, `acknowledged[]`, `dry_run`).
`PATCH` exists for the Form view as a convenience: the same request with only the changed paths, merged into the stored document by the server before the same diff, validation and warnings.

| BS | Routes | What |
|----|--------|------|
| **BS13** | the group; the warnings engine (`InfraWarnings`, §13.3 rule 6); the version hash; the `Utils_encodeJson`-exact reader and writer; `brain_infra_audit` and `brain_infra_history` (migrations); `StaffInfraAudit`; locks; the `no-store` header | the foundation; every later BS depends on it |
| **BS14** | `GET /overview` | one call for the hub: servers (active / total) with tenant counts and load against `specs.tenant_size`, tenants, DNS servers, zones, regions, clusters, the config `version` and last change, and **warnings**: tenants whose `public_ipv4` matches no server; an inactive server with tenants; a server over capacity; a `default_server` IP owned by no server; `json.tenants[]` that disagrees with the table |
| **BS15** | `GET /config`, `PUT /config`, `PATCH /config/sections/{id}`, `GET /config/history[/{id}]`, `POST /config/history/{id}/rollback` | the whole document, section patches, history with values, rollback |
| **BS16** | `/http/servers`: list, `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `POST …/retire` | the fleet; the response carries `tenant_count`, `capacity` and `would_be_selected` (what the provisioning picker would choose, F10) |
| **BS17** | `/http/tenants`: list (search, filters, paging), `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `POST …/retire` | tenants, full row, database credentials included |
| **BS18** | `/http/clusters`, `/http/regions`: `GET`, `POST`, `PUT`, `PATCH`, `DELETE` | clusters and regions |
| **BS19** | `/dns/servers`: list, `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `POST …/retire` | DNS servers, password included |
| **BS20** | `/dns/zones`: light list, `GET`, `POST`, `PUT`, `PATCH` (record operations), `DELETE`; `/dns/subdomains`: the same | zones read in **either** data shape and written in the normalised one **unless the JSON view saved the other shape on purpose**; record operations validated by type; the SOA serial raised to `YYYYMMDDnn`, strictly increasing; no response includes a raw database row the model did not produce (F9) |
| **BS21** | `GET /dns/named`, `PUT /dns/named` | named.conf, as JSON and as text, with `named_conf_edit` |
| **BS22** | `POST /http/tenants/{ref}/transfer`, `GET /jobs/{key}` | the transfer **wrapper**: builds the payload **from the database, not by calling the Brain's own routes** (F4), takes the locks, starts the existing background runner and returns `{tracker_key}`; `GET /jobs/{key}` returns the progress, the payload and the log |
| **BS23** | `POST /dns/apply` | **needs the work in F8 first**: a real "push this zone, the subdomains, or named.conf to the name servers" through the same background runner and a tracker key. Until it exists `infra_dns_apply` is `false` and the app says plainly that saved DNS changes **are not pushed from here** |
| **BS24** | none (uses the existing routes) | Apps: `brain/apps` needs no change; `whoami` already reports `apps` |
| **BS25** | none (uses the existing routes) | Storage: `brain/storage` needs no change; `whoami` already reports `storage` |

Migrations: `brain_infra_audit` and `brain_infra_history`. **No existing table changes** (a content hash is the version; OQ-I6 asks whether `updated_at` columns are wanted anyway).

## 13.6 The app

### Navigation

A **fifth destination, Infra**, between Billing and More (Members · Billing · Infra · More · Settings), present only when `capabilities.infra`. Its first screen is the **hub**:

```
 Infra
 ┌──────────────────────────────────────────────┐
 │ ⚠ 2 warnings                                  │  ← BS14, opens the list
 ├──────────────────────────────────────────────┤
 │ HTTP servers     3 active · 12 tenants        │
 │ Tenants          12                           │
 │ Clusters         1 · Regions 2                │
 │ DNS              2 servers · 14 zones         │
 │ Brain config     last changed 3 d ago         │
 │ Apps             ·    Storage   ·             │
 └──────────────────────────────────────────────┘
```

Each row appears only when its capability is true.

### The JSON editor

One widget, used for every resource and for the config's whole document, and **read-only when the capability or the screen is read-only** (P10 ships it read-only; the same widget turns editable per phase).
It is built in Flutter with `dart:convert` and a custom text controller: **no new package**.

- **Two modes, one document.** *Text*: monospace, line numbers, syntax colouring (keys, strings, numbers, `true`/`false`/`null`; colour is never the only signal, the token itself is the signal), wrap toggle,
  find and replace, undo and redo, jump to the error line, copy and paste of the whole document or a selection. *Tree*: collapsible objects and arrays; tap a leaf to edit it with a typed field (string, number,
  boolean, null); long-press for add key, add item, duplicate, delete, move. A document over about 200 KB opens in Tree first.
- **A key toolbar** above the keyboard: `{ } [ ] " : ,` and a tab (two spaces), because the phone keyboard buries them. Autocorrect, autocapitalise and suggestions are **off**.
- **Live validation.** Syntax errors are marked at the line and column the parser reports; **duplicate keys are flagged** (a plain JSON parse silently keeps the last one, which is silent data loss); the server's
  `dry_run` errors and warnings appear against the paths they name.
- **Format** (two-space indent) and **Minify**. **Key order is preserved** (the stored documents are order-sensitive to the humans who read them, and the legacy editors keep it); numbers round-trip as
  written (an integer stays an integer).
- **Review changes.** Before a save: the changed paths, **old and new value**, the warnings with their checkboxes (typed confirm on production), and the reason field. After a stale answer: the same
  review against the new copy.
- **Compare.** Any two history versions, or the draft against the saved copy, as a path-level diff.
- **Nothing is masked or collapsed away**: a secret is a string like any other, shown in the text, selectable, copyable. A **Copy value** button on a leaf copies exactly that value.

### Screens

Every resource's detail screen has a **Form | JSON | History** switch.

| Screen | Contents | Phase |
|--------|----------|-------|
| **Hub, warnings** | the BS14 counts and the warning list, each warning opening the thing it is about | P10 |
| **HTTP servers** | list (type, class, region, active, `tenants / capacity` bar: amber at 80%, red at 100%, `would be selected`); detail: addresses (copyable), specs, ports, users **and passwords**, tenants on it | read P10, edit P11 |
| **Tenants** | search (reference, member, domain, IP) with paging and filters; detail: domains, addresses, certificate paths, **database credentials**, `package_version`, owner server, a link to the member; **Add record**, **Retire**, **Delete**, **Transfer** | read P10, edit P11, transfer P14 |
| **Clusters, Regions** | lists and the Form / JSON / History views | P10 / P11 |
| **DNS** | servers (family-grouped, password shown); zones (search; domain, member, family, record counts, renewal date); a zone: SOA, NS and a **record list by type** (add, edit, remove sheets with the type's fields), a "serial will become …" line, the JSON view, and **Apply** (only with `infra_dns_apply`); subdomains; **named.conf** (JSON and text) | read P10, edit P13, apply P14 |
| **Brain config** | **Whole document** (the editor above) and the section list (a `critical` badge on the sensitive ones); a section is a form over the same document; **Sync repo URLs** on the git sections; **History** with values and **Roll back** | read P10, edit P12 |
| **Job** | steps, percent, messages, failure reason, the exact payload and the log; re-openable from the tenant or zone | P14 |
| **Apps, Storage** | clients of the existing routes: apps, settings, mail events, history and rollback; the storage fleet, devices, token keys, the members key; a new server credential is shown with a copy button, as the existing route returns it | P15, P16 |

### The shared pieces

- **One editing flow** for every resource: draft → dry-run → review (diff, warnings, reason, typed confirm) → save → one of *saved*, *stale* (409; Reload / Review against the new copy), *needs acknowledgement*
  (the warning codes), *failed*. An ambiguous timeout is settled by re-reading the resource and comparing; **a write is never sent twice** (BF9, file 05 §5.6).
- **`AcknowledgeSheet`**: the warnings of a dry-run, each with what it will do and what depends on it (a tappable list: the tenants on a server, the zones in a family), and the typed confirmation.
- **`JobProgress`**: polls with a growing interval, stops on completion, restores from the resource's own status after a restart, and shows a lost job honestly.
- Nothing from this feature is cached to disk except the **hub counts**; a document is never cached.

### Architecture

`features/infra/` with `hub`, `config`, `http_servers`, `tenants`, `clusters_regions`, `dns`, `jobs`, and `core/json_editor` for the widget (it imports no feature); `features/apps/` and `features/storage/` for P15 and P16.
Each feature owns `data`, `cubit`, `ui`; features still do not import one another's UI (G9b); the shell composes (the Infra destination, the member link from a tenant). `core/api/api_paths.dart` gains only
`/v1/brain/staff/infra/…`, `/v1/brain/apps/…` and `/v1/brain/storage/…`; the level-99 prefix table already covers them.

## 13.7 Phases

| Phase | Name | Server | App | Depends on |
|-------|------|--------|-----|-----------|
| **P10** | Read-only console | BS13, BS14, and the `GET` routes of BS15 to BS21 | the Infra destination; hub and warnings; **the JSON editor widget, read-only**; every list and detail in full; config sections and the whole document; history | P7 as built |
| **P11** | Fleet writes | BS16, BS17, BS18 writes | edit, create, retire and delete servers, tenants, clusters, regions, as a form **and as JSON**; the acknowledge flow | P10 |
| **P12** | Config writes | BS15 writes | the whole-document editor and the section forms; review; history; rollback | P10 |
| **P13** | DNS writes | BS19, BS20, BS21 writes | DNS server edit; zone records and zone JSON with serial handling; subdomains; named.conf | P10 |
| **P14** | Jobs | BS22, BS23 | tenant transfer, DNS apply, the job screen | P11, P13, **the F4 and F8 findings confirmed and resolved** |
| **P15** | Apps control plane | BS24 (none) | the apps client, the manifest as JSON | P7 as built |
| **P16** | Storage fleet | BS25 (none) | the storage client | P7 as built |

P12 is the highest-risk phase (one row feeds every system email, every git operation and every transfer) and is deliberately **after** P11, so the version, review, acknowledge and history flow is proven on
lower-stakes editors first. P14 is the only phase that can change running infrastructure from the phone, and is blocked until the two read-through findings are confirmed on the lab.

## 13.8 Tests and acceptance

### Server (never run so far on the authoring PC; written to the standard of the earlier BS tests)

- **Nothing is hidden, as a property:** for each resource, every column and every key of the decoded `json` in the database appears in the `GET` response, including secrets, unknown keys and legacy fields.
- **Round-trip fidelity:** `GET` then `PUT` the same document is a no-op (no change reported, the version unchanged, no history row); key order, number types and unknown keys survive; the stored text is still
  `htmlentities(json_encode(…))` and the **legacy readers** (`Utils_decodeStringToJson`) parse it.
- **Stale writes:** a stale version is 409 and writes nothing; a legacy write between read and save makes the version stale; **no acknowledgement ever overrides it**.
- **Errors and warnings:** each error case is refused; each warning code in §13.3 rule 6 is raised by exactly its condition, a save without its code is refused, and a save with it succeeds and is audited with the codes.
- **Dry run:** returns what the real save returns and writes nothing (no row, no history, no audit).
- **History:** every write adds a snapshot with the full previous document; rollback restores it and adds another; a delete is restorable; history responses include the values.
- **Zones:** both data shapes read; the normalised shape written unless the JSON chose the other; every record validation; the serial is strictly increasing across saves in one day and across days.
- **Transfer wrapper:** the payload is built from the database with the Brain's own routes **not** called; the job response carries the payload and log; the payload file is `0600` and gone afterwards; a second
  request while one runs is 423; the target rules.
- **Authorization:** every route is 401 with no token, 404 for a non-99 token and off the Brain, as for `whoami`; every response has `no-store`.
- **Audit:** a write that cannot be audited is not made.

### App

Cubit tests for the one editing flow (draft, dry-run, review, stale, needs-acknowledgement, ambiguous, success); **editor tests**: valid and invalid JSON, duplicate-key detection, key order and number preservation
through Format, tree edits producing the same text as typed edits, a 1 MB document opening in Tree, undo and redo; widget tests for each screen and sheet; the typed confirmations on production; the job poller;
capability gating of every row; **a test that no infrastructure text reaches `debugPrint`, the cache or a preference**; and **additions to the existing tours**: every new screen is added to
`test/support/screen_tour.dart`, which runs the 48 dp, label, contrast and 200% text checks and the reachability assertion. The navigation test's exclusion scan changes from "no source names a DNS, HTTP, storage
or Apps route" to **"no source names a legacy route: `/v1/brain/config`, `/v1/brain/dns`, `/v1/brain/http`"** (only `/v1/brain/staff/infra`, `/v1/brain/apps` and `/v1/brain/storage` are allowed).

### Exit checks (lab2, on the phone)

| Phase | Exit check |
|-------|------------|
| **P10** | The hub's counts agree with the browser's lists; a deliberately orphaned test tenant shows as a warning; every document opens in full and matches the browser's JSON; the JSON view of the config round-trips (Review shows no changes) |
| **P11** | Add a throwaway server as JSON, edit it as a form, retire and delete it; retiring a server with a tenant lists the tenant and needs the typed acknowledgement; a tenant edit made as JSON shows in the browser; a stale save is refused |
| **P12** | Edit a non-critical field and see it in the browser; edit a throwaway secret and see the new value on the next read; change a git token and take the **Sync repo URLs** warning; reorder `email_providers` and take `mail_provider_position`; roll back and see the previous state |
| **P13** | Add, edit and remove records on a throwaway zone, in the form and as JSON; the serial rises each time; a CNAME at the apex is flagged `zone_invalid` and needs acknowledgement; named.conf opens in both views; the zone reads the same in the browser |
| **P14** | After F4 and F8 are confirmed: transfer a throwaway tenant to a second lab server and watch the job to completion, kill the app mid-job and reopen onto the same job; open the job's payload and log; apply a throwaway zone and see it answer from the name server |
| **P15, P16** | The Apps and Storage screens round-trip with their browser pages; a new storage credential is shown as the route returns it |

## 13.9 Open questions

| # | Question | Needed by |
|---|----------|-----------|
| **OQ-I1** | **How does a DNS change reach BIND today?** By reading, the Laravel reload routes do nothing and the legacy save only writes the database (F8). Is there another path (a cron on the Brain host, a script watching the table)? | P14 |
| **OQ-I2** | *Decided 2026-10-05: nothing is masked and there is no reveal step.* The protections are the ones in §13.3 rule 2 (no logging, no disk, `no-store`, `FLAG_SECURE`, the app lock). Open only: should the infrastructure screens **also** require the app lock to be on in a production build? | P10 |
| **OQ-I3** | **Close the legacy routes?** `config`, `dns/*` and `http/*` answer any logged-in portal user and return secrets (F1, F5, F6). They were opened on purpose in August for other callers. Which callers still need them, and could they use a service credential instead? This file does not touch them. | independent; recommended before P12 ships |
| **OQ-I4** | What reads `controller.portal_ip_login`? Nothing in `www` does. If a script or another app uses it as an admin allow-list, an edit could lock the browser out. | P12 |
| **OQ-I5** | Is moving a tenant (F3, F4) working today at all? It needs a throwaway tenant and a second server on the lab to find out. | P14 |
| **OQ-I6** | A content hash is proposed as the row version. Do you also want `updated_at` columns on these tables (a migration on a legacy schema the browser writes with raw SQL)? | P10 |
| **OQ-I7** | Should the provisioning picker (`available`, F10) be corrected to skip Standby, Backup and Clone servers and servers at capacity? The app will only *show* what it would pick; changing it changes where new websites go. | independent |
| **OQ-I8** | *Decided 2026-10-05: the app can create a tenant record*, with the `tenant_record_only` warning (it provisions nothing). Should it ever **provision** one (system user, database, certificate, DNS)? That is the member-domain flow, not this file. | — |
| **OQ-I9** | The example JSON files in the repository hold what look like live credentials. Rotate, and replace them with placeholders? | independent |
| **OQ-I10** | `id` and `date` are shown but not writable. Is there any case where you want to edit them (restoring a deleted row with its old `id`, for example)? History restore does that today. | P11 |

### What P11 settled that the plan left open

- **PATCH was dropped.** Writes are whole-document `PUT`s with `expected_version`; a partial update would make the review ambiguous (what is the diff against?), and the editor always holds the whole document.
- **Rollback is one generic route** (`POST /history/{id}/rollback`) rather than one per resource, because a snapshot already says which resource and row it is for. It reviews and acknowledges like any other write.
- **`1` and `1.0` differ in PHP's decode but not in the app's diff.** The server diff treats a JSON integer and a float with the same value as different (it compares decoded PHP values strictly); the app's own diff compares printed JSON. If a document only changes `1` to `1.0` the server reports a change and the app's draft does not mark it dirty. Not worth a rule yet; noted.
- **The Form is generated from the document.** There is no hand-kept field list to go stale; a new column on the server appears in the form the day the server returns it. Creation starts from a small per-resource template (`infraTemplate`), and anything else is added in the JSON tab.
- **Only HTTP servers, tenants, clusters and regions can be added by hand** in P11. DNS rows are written in P13, and the config is one row (edited, never added).
