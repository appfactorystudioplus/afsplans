# 13 — Managing the Brain's infrastructure — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (G1–G9, `flutter analyze` clean, `flutter test` **1158 passed**). The app side is covered by fakes; every screen of this file is in the shared screen tour, so the tap-target, label, contrast and 200%-text guidelines run on all of them.
**Server (`w/www`):** all code lints (`php -l`) and every route is registered (`php artisan route:list`). The pure logic (`InfraDiff`, `InfraConfigRules`, `InfraDnsRules`) was run directly through `php artisan tinker` and behaves as the tests say. **No PHPUnit file of this plan has ever been run**: this PC has no database. That covers `BrainStaffInfraReadTest`, `BrainStaffInfraWriteTest`, `BrainStaffInfraConfigWriteTest`, `BrainStaffInfraDnsWriteTest`, `BrainStaffInfraJobTest` and the updated `BrainStaffWhoamiTest`.
**Device:** the P11 build was installed and started on the phone (debug, because only debug builds trust the lab CA). P12 to P16 were not. Nothing has been exercised against a server that has these routes.
**Migrations:** one, from P10 (`brain_infra_audit`, `brain_infra_history`). Nothing after it adds a table or changes a legacy one.

**Status:** **Complete in code, with two deliberate exceptions (below); not verified against a database, a lab or a device.** Every acceptance line of §13.8 is covered by a test on one side of the wire; the lab checks are listed at the bottom, and nothing in this file should be called working until they have been run.

## What was built

| Phase | Server (`w/www`) | App (`afsbrain_flutter`) |
|-------|------------------|---------------------------|
| **P10** read-only console | the group, the resource registry, the exact-encoding reader, the version hash, the audit and history tables, the read routes of BS14 to BS21, `whoami` capabilities | the Infra destination, the hub with warnings and load bars, a list and a Form / JSON / History screen for every resource, the config Sections tab, the JSON viewer |
| **P11** fleet writes | `InfraDiff`, `InfraValidator`, `InfraWarnings`, `InfraWriter`, `StaffInfraWriteController`; create, replace, retire, delete for servers, tenants, clusters and regions; one generic rollback; `infra_write` | the editable JSON editor (text and tree), the generated Form, the review screen, the edit cubit (dry run, stale, lost-answer settling), Edit / Add / Retire / Delete / Roll back |
| **P12** config writes | `InfraConfigRules` (errors for addresses, the allow-list, duplicate key names, https redirect; warnings `access_list_changed`, `mail_provider_position`, `consumer_secret_cleared`, `embedded_credential_stale`, `unknown_key`); `sync_embedded_urls`; `PUT /config/{ref}` | the Sections editor over the shared draft, a section as a small JSON document applied back to the whole, the `critical` marking, a typed confirmation on production for any change under a critical section, **Sync repo URLs** |
| **P13** DNS writes | `InfraDnsRules` (both zone shapes read, neither rewritten; the SOA serial raised strictly and shown in the review; `zone_invalid`, `zone_serial_not_increased`, `zone_record_only`, `last_dns_server_in_family`, `named_conf_edit`); writes for DNS servers, zones, subdomains and named.conf | the zone Records editor (by type, add / edit / remove sheets, name servers, the "will become" serial line, conversion of a legacy zone that keeps every key), templates for DNS rows |
| **P14** jobs | `InfraTransfer` (payload built from the database, locks, idempotent start, audit first), `InfraJobRunner`, `StaffInfraJobController`; `POST …/transfer`, `GET /jobs`, `GET /jobs/{key}`; `infra_jobs`, `infra_transfer` | the transfer flow (choose a server, read the exact payload, acknowledge, reason, typed name on production), the job screen (steps, payload, log; polls, pauses in the background, reads again on return), a tenant's transfers |
| **P15** Apps | none (existing `/v1/brain/apps`) | apps list, an app's Overview / Settings / Mail / Manifest / History, setting edit with revision, reset, definitions, mail handler JSON, test send, mail log, rollback |
| **P16** Storage | none (existing `/v1/brain/storage`) | the fleet with health and fill, a server (edit, rotate), a device (edit, reports, events), the keys (token keys, members key), the credential shown once with a copy button |

## What is **not** built, and why

1. **DNS apply (BS23).** `infra_dns_apply` is reported `false` and there is no route. Reading `reload_named2`, `build_and_reload_named` and `RunCommand` showed that a real apply needs `get_brain_settings`, `_createTempararyDNSConfigurationFile`, `BuildNamedConf`, `CreateZoneFile`, `SaveDNSDomainFiles` and an `exec` of a script on the Brain host. None of that can be exercised here, and a DNS push that was written blind is the wrong thing to ship. **The zone editor says so in words** ("Saving changes the database only. Nothing here pushes a zone to a name server."), and saving a zone does **not** regenerate `json_zone_files`. Finding F8 stays open and is now sharper: the Laravel reload routes are stubs, and nothing in the API applies DNS.
2. **Starting a transfer is switched off by default.** The wrapper is built and tested with a fake runner; `config('brain.infra_transfer_enabled')` (env `BRAIN_INFRA_TRANSFER=false`) must be turned on, on a host where the runner has been confirmed, before a real start works. Until then a real start answers `403 BS_E_DISABLED`, the capability `infra_transfer` is `false` (the Transfer button does not appear), and a dry run, the payload, and reading jobs all work. **Finding F4 is confirmed by reading**: `moveTenants` calls `getTenantData`, which fetches zones, servers and subdomains from the Brain's own routes over HTTP with no credentials, and those routes need `portal.admin.auth`; the payload it builds is therefore empty. The wrapper builds the same payload keys from the database instead. Whether the runner then works end to end is **unverified** and is OQ-I5.

## Divergences from the plan

- **Sections are JSON slices, not typed forms.** §13.4 describes a form per section. Each section opens as a small JSON object (`{ "controller.brain_host": … }`) in the same text and tree editor, and applying it sets or removes exactly those paths in the shared draft. It reaches every field and shows every secret; it is less pleasant than a form. `email_providers` and `keys` are edited as arrays in the tree, not by `identifier` or `name`.
- **No PATCH routes.** Every write replaces a whole document with `expected_version` (P11's decision), so there are no `PATCH /config/sections/{id}` or record-operation PATCH routes; the zone editor builds the whole document client-side.
- **No named.conf text view.** named.conf opens as the row's JSON (Form and JSON tabs). The plan's separate text view was not built.
- **The Form is generated from the document**, not a hand-kept field list (P11), so no field can be missing from it.
- **The hub's Apps and Storage rows sit at the bottom of the Infra hub**, not in a separate destination, and need the `apps` and `storage` capabilities.
- **A config save asks for the typed confirmation by the section's `critical` flag** (any change under a critical path), in addition to the acknowledged-warning rule.

## Findings (plan 13 §13.1) as they stand

| # | State after this file |
|---|----------------------|
| F1, F5, F6 | **Unchanged and still open.** The legacy `config`, `dns/*` and `http/*` routes answer any logged-in portal user. This file never calls them (a test fails if a source names one) and does not close them. OQ-I3 |
| F3 | The wrapper does not return or write the full config on disk with the response; the payload file is written mode `0600` and removed when the job is seen to end. The runner itself still receives the secrets: that is what it needs |
| F4 | Confirmed by reading; bypassed by the wrapper (above). Unverified end to end |
| F8 | Confirmed by reading; **not fixed**; apply not built (above) |
| F9 | The staff DNS routes return documents, not raw model rows |

## Open questions that were answered by building, and the ones that were not

- **OQ-I2** (nothing masked) — decided 2026-10-05 and built that way.
- **OQ-I6** (`updated_at`) — a content hash is the version; no migration.
- **OQ-I8** (create a tenant record) — built with `tenant_record_only`.
- **OQ-I10** (editing `id` and `date`) — never written; rollback of a delete restores the old `id` on purpose.
- **Still open:** OQ-I1 (how DNS reaches BIND), OQ-I3 (close the legacy routes), OQ-I4 (what reads `portal_ip_login`; the warning exists), OQ-I5 (does a transfer work), OQ-I7 (the provisioning picker), OQ-I9 (credentials in the example JSON files).

## To run on the lab, in this order

1. `php artisan migrate` (one migration, from P10). `php artisan route:clear`.
2. On a **test** database: `php artisan test --filter=BrainStaffInfra` and `--filter=BrainStaffWhoamiTest`. These tests **back up and restore** the single `brain_config` row and the named.conf row; run them on the lab.
3. Deploy the `w/www` files listed in `handoff-share.md` sections 16 to 20. Verify with **no token** that `POST …/infra/http/servers`, `PUT …/infra/config/x`, `POST …/infra/http/tenants/x/transfer` and `GET …/infra/jobs` answer 401, and that a level below 99 answers 404.
4. On the phone (debug build): the §13.8 lines below. **Leave `BRAIN_INFRA_TRANSFER` off** until step 5.
5. To enable transfers: confirm `core/scripts/background_runner.php --transfer-tenants` on the lab host with a throwaway tenant and a second server, set `BRAIN_INFRA_TRANSFER=true`, and run the P14 line.

## Acceptance (§13.8): lab checks still to run

| Phase | Check |
|-------|-------|
| P10 | the hub's counts agree with the browser's lists; every document opens in full and matches the browser's JSON; the config's JSON round-trips (the review shows no changes) |
| P11 | add a throwaway server as JSON, edit it as a form, retire and delete it; retiring a server with a tenant lists the tenant and needs the acknowledgement; a stale save is refused |
| P12 | edit a non-critical field and see it in the browser; change a git token and take **Sync repo URLs**; reorder `email_providers` and take `mail_provider_position`; roll back |
| P13 | add, edit and remove records on a throwaway zone, in the Records tab and as JSON; the serial rises each time; a CNAME at the apex needs acknowledgement; a legacy-shaped zone converts and the review shows every key kept |
| P14 | dry-run a transfer and read the payload; with the switch on, transfer a throwaway tenant and watch the job; kill the app mid-job and reopen onto the same job from the tenant's transfers; repeat the start with the same key and see no second job |
| P15, P16 | the Apps and Storage screens round-trip with their browser pages; a new storage credential is shown exactly as the route returns it |
