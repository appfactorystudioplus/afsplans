# 13 — Turning it on: completion report

**Plan file:** [`../13-turning-it-on.md`](../13-turning-it-on.md)
**Date:** 2026-10-24
**Status:** ✅ P0, P1 and P2 built and tested. Not run on the dev server (§4). Nothing is committed.

This report replaces the one written on 2026-10-23. That version described a `controller.domains` block of 99, then 14 settings, a per-member switch to the new flow, and four on/off switches. The owner removed all of it (the application is not published, there are no domains and no old flow), so what follows is the final state. The steps are in `../complete/00_extras.md` E12 to E19.

## 1. What was built

| Plan section | Built as |
|---|---|
| §13.2 one flow | `w/members`: `routes/web.php` sends list, search, release, add (`/claims`), buy (`/purchases`), verify, nameservers, records and the website routes of `/api/v1/domains` to `DomainSurfaceController`. Removed: the old `DomainController` methods `index`, `store`, `destroy`, `update`, `search`, `purchase` and their helpers (`brainZones`, `brainSaveZone`, `ownsZone`, `_searchNameCom`, `_runNameCom`, `getRegistrarCredentials`, `createDefaultMemberZone`), `DomainFlow`, `DomainFlowController`, `RequireNewDomainFlow`, `RefuseOldDomainFlow` and their aliases. `DomainController` keeps `createWebsite`, `listWebsites`, the progress methods and the build helpers (the old shell pipeline). The browser always runs in `surfaceMode`. |
| §13.2 no domain settings | `w/www`: `DomainSettings` is now only `get()`; the machinery for a settings document is gone (`KEYS`, `load`, `document`, `missing`, `invalid`, `only`, the `domains:settings` command, the boot-time load, the `InfraConfigRules` checks, the editor schema block, the seed block, `DomainSettingsFixture`). Every value is in `DomainDefaults`, including the platform names (`sites_zone` `sites.appfactory.studio`, `platform_zone_parent` `appfactory.studio`, `hostmaster`, `platform_ns`, `reserved_names`, `extra_allowed_suffixes`, `parking_ipv4`), the template values (`deploy.template_*`, empty), the ssh helper path and the Brain's own address (`storage_agent.brain_url` is `app.url`). The Brain's `domains/flow` endpoint is gone. |
| §13.2 environment | `config/app_environment.php` and `App\Support\AppEnvironment` in `w/www`, the same rule as `members` (`VITE_SUBDOMAIN`: `www15` development, `www` or missing production). The registrar is `controller.domain_registrar`; `RegistrarCredentials::selected()` reads it and `get()` takes the `development` or `production` slot of its `controller.keys` entry. `NameComDriver` takes its slot and base URL from `AppEnvironment` (E14). |
| §13.2 wildcard certificate | `SitesCertSettings` keeps only `controller.letsencrypt_cert_management.tenant_cert` and `tenant_cert_key`; the zone is `sites_zone`, the e-mail is built in, there is no staging and no environment (E20): one real Let's Encrypt, and the run is always on. The editor schema and the seed hold the nested `letsencrypt_cert_management` group with the pair only. |
| §13.2 no switches, no guard | Removed: `claims_enabled`, `publish_enabled`, `purchases_enabled`, `deploys_enabled`, `env`, `registrar_driver`, `registrar_env`, `allowed_server_cidrs`, `new_flow.*`, and the code behind them: the `DnsGuard` class and every use of it (the two ssh transports, `DnsPublisher`, `DnsReconciler`, `DeploymentService`, `StorageConnector`), `domains:seed-lab`, and the `blocked` / `disabled` results of the publish and reconcile commands. |
| §13.2 staff path | `BrainDomainStaffController` and the routes `GET domains-staff/contested`, `POST domains-staff/claims/{claim}/suspend`, `.../unsuspend`, `POST domains-staff/transfer` (`brain.only` + `portal.admin.auth` + `portal.level99`; below level 99 the answer is 404; suspend and transfer need a reason; the audit actor is `staff:<reference>`). A **Domains** page in the Brain admin (`controllers/brain/domains/brain_domains.ts`, view `brain:domains`, a button on the Brain page) lists the contested names with holder and waiting claims and offers Suspend, Give the name to this member and Unsuspend. |
| §13.4 P2 | `domains:health` raises `invariant_zone_without_claim` for a live zone with a null `claim_status`. A claim's data carries `last_check` (`reason`, `passed`, `checked_at`) and the verify screen shows it when it opens on a claim that was already checked. |

## 2. Verified

* **Brain (`w/www`), on a local MariaDB** (started and stopped by the test script; nothing left running): `tests/Feature/Brain/Domains` (every domains test), `SitesCertTest`, `BrainStorageConnectTest` and `BrainBuildSurfaceTest`: **324 tests, 3991 assertions, 0 failures, 21 skipped** (the tests that need real VMs).
* **Members (`w/members`)**, same MariaDB: `DomainSurfaceControllerTest`, `DomainRoutesTest` (new: the routes all go to the pass-through; no old add, purchase or hand edit exists; the website build is still the old controller and every route needs a signed-in member) and `DomainBrainSurfaceTest` (the website build): **30 tests, 139 assertions, OK.**
* **TypeScript:** `tsc --noEmit` in `w/www`: 0 errors. In `w/members`: 62 errors, the same 62 as before this work, none in `resources/js/controllers/domains/`.
* New tests: `DomainStaffTest`, `DomainSettingsTest` (rewritten for `get()` and `DomainDefaults`, including a scan that every key the code reads exists), `DomainRoutesTest`, two `AppEnvironment` / registrar tests, one `SitesCertSettings` test, `domains:health` and `last_check` cases.

## 3. Mistakes found and fixed on the way

* The editor schema lost commas inside groups when I trimmed it; the text-based test passed anyway and only `tsc` showed it.
* Redoing that trim with `git checkout` threw away the nesting of `letsencrypt_cert_management` in `brain_controller_schema.ts` (it had never been committed). It was noticed while removing the certificate settings and restored by hand; the schema now has the nested group with the pair.
* A method-removal script stopped at the wrong brace because one method ended in `} ` with a trailing space, and also deleted `listWebsites`; restored from git before anything else ran.
* I had closed `POST /create-website` for members on the new flow; that is the old website build. Removed with the rest of the flow switch.

## 4. Not done, and why

1. **A run on the dev server with two real accounts and a real DNS zone.** Neither exists in this session. The behaviour is covered by the tests above, not by a run.
2. **The old browser code is still in the repository.** `api.ts`, `dns.ts`, `purchase.ts`, `search.ts`, `website.ts` and the `else` branches that run when `surfaceMode` is false are unreachable now (`surfaceMode` is always true). Deleting them means rewriting the screens that still mix both modes, which I did not do here.
3. **`platform_ns` is only `ns1.appfactory.studio`** (the only name server in the seed), and `parking_ipv4` is empty. They are in `DomainDefaults`; set them there when the real values are decided.
4. **The older plan files and reports** (steps 03, 05, 09 and their reports) still describe the settings and the lab as they were written.
5. **Not run:** the rest of the Brain and members suites outside the folders listed in §2; a browser check of the new Domains page and the verify screen; the Let's Encrypt issue on a real server.
