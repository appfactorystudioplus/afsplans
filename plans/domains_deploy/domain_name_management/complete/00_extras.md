# domain_name_management — off-plan work (`complete/00_extras.md`)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/00_extras.md](../../new_way/complete/00_extras.md). Numbers (§, F, D, E) are unchanged.

What was actually done outside a numbered step, dated, for the domain-name side and for the infrastructure both features share (the settings, the ssh runner, the per-member switch). The numbered plan files say what is *intended*; this file says what exists. Section numbers E1–E11 are unchanged from the combined file.

## E1 — Lab test domains `tenant1`–`tenant3.10-0-0-243.sslip.io` (2026-10-07)

Owner request: because the lab's DNS is private, use names that follow the lab convention and run the whole sequence by hand — zone file,
zone stanza in `/etc/named.conf` on the DNS VM, then the website.

Built (all under `C:\projects\vagrant\lab2`):

| File | Purpose |
|---|---|
| `dns/lab-domains.list` | fqdn, `site_ref`, ip for the three domains (`sv5eaw74n4ln`, `s707ee8no009`, `su8oc4ii77dz`, all `10.0.0.243`) |
| `dns/add-zone.sh` | On the DNS VM: zone file in the `test2.zone` structure, marked zone stanza appended to `/etc/named.conf`, `<ref> IN A` in `tenants.zone`, `test2` serial bump, check, `rndc reconfig`/reload, `dig` checks |
| `provision/dns.sh` | Re-creates the stanzas/zones from the list on re-provision; installs `tenants.zone` only when absent |

Result, checked from the PC: the DNS server answers authoritatively (`aa`) for SOA/A/CNAME/TXT and the sites record on all three; each site
answers `200` with `"db":true` on `https://tenantN.10-0-0-243.sslip.io/health.php` (certificate verified) and on `https://<ref>.sites.test2`
(via `--resolve`); `http://www.tenantN…` answers; `http://tenantN…` redirects to HTTPS; other lab apps unaffected. Backups on the DNS VM:
`/etc/named.conf.pre-lab-domains`, `test2.zone.bak-*`, `tenants.zone.bak-*`.

Not done: the production rows remain in the lab **seed file** (09 §9.1). The running database was checked 2026-10-08 and its server rows point at lab hosts (`10.0.0.242`, `.243`, `.244`); the lab DNS server row (id 2, `10.0.0.242`) was set active on 2026-10-08.

> **E2 (Gitea settings, `app_install.sh`, `LETSENCRYPT_CHECK`), E3 (tenant-host dependency access), E4 (the template copy script) and E5 (progress step numbers, tried and reverted)** are website-deployment work: [../../new_way/complete/00_extras.md](../../new_way/complete/00_extras.md).

## E6 — Production-style paths and consolidated vhost files in the lab (2026-10-08)

Owner request: everything should mimic production, including the paths, and the platform's nginx and Apache files should be single files as on production.

- **DNS VM (10.0.0.242):** `dns/migrate-to-zones-dir.sh` moved `test2.zone`, the sites list (now `zones/test2.include.zone`) and the three domain zones into
  `/var/named/zones/`, rewrote the `/etc/named.conf` stanzas to `file "zones/<name>.zone";` and the `$INCLUDE` to its full path. Backed up to
  `/root/dns-layout-backup-20261008-190606`; every name still resolved afterwards. Repo: `dns/add-zone.sh`, `provision/dns.sh`, `brain/deploy-tenant.sh` use the
  new paths; seed `dns/tenants.zone` renamed `dns/test2.include.zone`.
- **Not mimicked:** production sites-name zone `appfactory.studio` / `sites.appfactory.studio` (the lab keeps `test2` / `sites.test2`), and the `www` + `www-own` pair of
  copies per tenant (the plan uses one docroot).

## E7 — All domains settings moved into the Brain settings; no `.env`, no defaults (2026-10-09)

Done at the owner's instruction ("everything must come from the Brain settings … no fallbacks"). It changes how every step 02–08 report below reads its settings.

- **Where they live now:** the Brain settings document, `brain_config` (reference `default`) > `controller.domains`, edited in the Brain settings editor (Controller > Domains; `w/www/resources/js/portal/admin/afscore/schema/brain_controller_schema.ts`). 95 settings (98 before E8, 93 after it, +2 for E9 removed the ssh key and user settings), grouped like the old config (claims, DNS publishing, DNS agent, purchases, server selection, website building, tenant agent).
- **Code:** `App\Services\Brain\Domains\DomainSettings::get('deploy.step_attempts')` is the only way the code reads one. A setting that is absent or `null` throws `DomainSettingMissing` (`DOM_E_SETTING_MISSING`, 503) naming it; `''`, `false`, `0` and `[]` are values. `DomainSettings::load()` reads the document at boot on the Brain and before every queued job (a long-lived worker sees editor changes without a restart). `config/domains.php` holds only the two protocol constants (`challenge_label`, `challenge_prefix`). Every inline default (`?? 22`, `config('domains.x', 3)`) was removed from the domains code, the two ssh transports included.
- **Checks:** saving the Brain settings refuses a wrong-typed value and warns about missing ones (`InfraConfigRules`); `php artisan domains:settings` lists all of them (secrets masked) and exits 1 if any is missing or wrong; a test scans `app/`, `config/` and `routes/` and fails if any file reads `config('domains.*')` directly or a `DOMAINS_` env key.
- **Old name → new key:** `DOMAINS_SELECT_REGION_POLICY` → `selection.region_policy`; `DOMAINS_TENANT_SSH_USER` → `tenant_agent.ssh_user`; `DOMAINS_TEMPLATE_REF` → `deploy.template_ref`; `DOMAINS_DNS_SITES_INCLUDE` → `sites_include_file`; `DOMAINS_PUBLISH_ENABLED` → `publish_enabled`; and so on (lower-case, the prefix becomes the section). The reports for steps 02–08 still say `DOMAINS_*`; read them with this table.
- **Lab:** the lab values were written into the lab database's document (backup table `_backup_20261009_brain_config`) and the `DOMAINS_*` lines were removed from `_env/lab2/.env`; `domains:settings` against it: 93 settings, 0 missing, 0 wrong.
- **Tests:** they start from a complete document (`tests/Support/DomainSettingsFixture`, applied in `tests/TestCase.php`) and override what they test; `DomainSettings::load()` does nothing under test.
- **`DOMAINS_USE_BRAIN_SURFACE` (the members switch of step 08) is gone:** see E9.

## E8 — ssh to other servers: password, bash + expect, no keys (2026-10-09)

Done at the owner's instruction (no key management; `expect` for what must be answered, bash as the driver). Replaces the key-first transports of steps 04 and 07.

- **Runner:** `w/www/core/scripts/ssh/afs-ssh-run` (bash) and `afs-ssh-run.expect`. `AFS_SSH_PASSWORD='…' afs-ssh-run [--port N] [--timeout S] [--option K=V]… user@host -- '<remote command>'`; stdin goes to the remote command; stdout is the command's stdout only; stderr is kept apart; the exit status is the remote command's (255 = login or connection failed, 124 = timed out, 2 = bad call, 127 = no `expect`). Expect answers the host-key question and the password prompt (once); bash does everything else.
- **Where the login comes from:** the server's own record — a DNS server's `username`/`password`, a web server's `ssh_user` (or first `json.users[]` entry) and that user's password (`App\Services\Brain\Ssh\ServerLogin`). No keys, and no user or password in the Brain settings. A record with no login stored is refused by name (`DOM_E_SERVER_LOGIN`); nothing is guessed.
- **The password:** in the runner's environment only — never an argument, a file or a log line. The request (which can hold the template token) is held in a mode-600 file on tmpfs (`/dev/shm`) for the length of the call and removed on exit.
- **Settings (93 now):** `dns_agent` and `tenant_agent` keep `path`, `ssh_port` (used when the record has none), `sudo` (`sudo -n` for a non-root login with passwordless sudo for the agent), `timeout_seconds`, `ssh_options`, and gain `ssh_runner` (the script's path on the Brain machine). `ssh_user`, `ssh_key`, `ssh_binary` and `sshpass_binary` are gone; `sshpass` is no longer used.
- **A defect found and fixed:** the first version gave ssh its request as a redirected FILE; ssh then answered 255 about half the time after a good login (1 right in 12). Giving it the request through a pipe made it right 40 times out of 40, and `LabSshRunnerTest` guards it.
- **Verified on the lab:** `LabSshRunnerTest` (9 tests, opt-in `AFS_LAB_BRAIN=1`) runs the real runner on the Brain VM to a throwaway user on the web VM (random password, removed afterwards): stdin/stdout/stderr/exit status, a wrong password (255, no password in the message), the host-key question, the real tenant agent's `ping`, 280 KB out and 1 MB in intact, the timeout (124), no password in any process argument, a private request file removed afterwards, an unreachable server (255).
- **Not verified:** a production server; the production Brain's OpenSSH and `expect` versions; an `expect`-less host (the runner answers 127 and says so). This PC (Windows) has no `expect`, so the other lab tests (`LabDeployTest`, `LabDnsPublishTest`) use the stand-in `tests/Support/lab_ssh_runner.sh`, which takes the same arguments but logs in with the lab key — the PHP around the runner is real, the runner's own login is covered by `LabSshRunnerTest`.

## E9 — Who is on the new flow is a Brain setting, per member (2026-10-09)

Replaces the members `.env` switch `DOMAINS_USE_BRAIN_SURFACE` of step 08 (the owner's rule: settings come from the Brain, not from `.env`).

- **Brain settings:** `new_flow.all` (bool) and `new_flow.members` (list of member references), edited in Controller > Domains. `POST /api/internal/brain/domains/flow` answers `{new_flow: bool}` for the member in the call.
- **Members:** `App\Services\Www\DomainFlow` asks that route (signed) and remembers the answer 60 s; if the Brain cannot be asked the answer is "old flow", remembered 10 s. `DomainFlowController` serves the four shared URLs for either flow; `RequireNewDomainFlow` (`domains.new_flow`) makes the Brain-only URLs a 404 for a member not switched over. `config('services.brain.domains_surface')` and the `DOMAINS_USE_BRAIN_SURFACE` key no longer exist.
- **Effect:** flipping a member (or everyone) is an edit in the Brain settings editor and takes effect within a minute; no file is touched and no deploy is needed. See `complete/10-rollout-and-testing.complete.md` §3.

## E10 — `NameComDriver` moved from Name.com API v4 to CORE v1 (2026-10-09)

Done at the owner's instruction (decision D21). Written from Name.com's OpenAPI reference (`info.version 1.35.0`), not from memory.

- **Paths:** everything now under `/core/v1` (`domains:search`, `domains:checkAvailability`, `domains/{name}:getPricing`, `domains`, `domains/{name}`, `:setNameservers`, `:enableAutorenew|:disableAutorenew`, `:renew`, `:lock|:unlock`). The base URLs and the environment are Brain settings (`namecom_base_url_production` / `_sandbox`, `registrar_env`); the credentials are the `namecom` entry of the Brain document's `controller.keys` (slots `production` and `development`, fields `username` and `token`; the sandbox user name is the normal one with `-test` appended and has its own token).
- **What changed besides the prefix, because CORE v1's rules differ:**
  - `X-Idempotency-Key` is sent on register (a stable UUID-shaped value made from the saga's key), so a retry after a timeout returns the original result and never buys twice. A 409 that says the key was reused for a different request is an `ERROR` (our bug), not a decline, so the saga does not refund on it.
  - `purchaseType: registration` is sent on search, availability and create. **Only registrations are for sale**: Name.com's discovery also returns aftermarket, expiring and backorder inventory (flat acquisition fees, different rules); those results are reported as not purchasable.
  - `quote()` re-checks the exact name with `checkAvailability` (Name.com's recommendation, immediately before a purchase); for a **registry-premium** name the price comes from `getPricing?years=1`, the figure create must be given.
  - `purchasePrice` is still sent on create: Name.com refuses (400) when it does not match its current price, so a price that moved can never be charged to the platform unpaid; the saga refunds on that answer. A 402 (our Name.com account cannot pay) is a decline with Name.com's `details` kept in the message.
- **Verified:** `RegistrarDriversTest` and the purchase tests (35 tests, 247 assertions) with faked HTTP; and a **read-only probe of Name.com's sandbox** with the credentials stored in the lab Brain document — `GET /core/v1/hello`, `POST /core/v1/domains:checkAvailability` and `POST /core/v1/domains:search` all answered 200 in the field names the driver reads (`domainName`, `purchasable`, `purchasePrice`, `purchaseType`, `renewalPrice`, `premium`).
- **Sandbox run (2026-10-09, owner's go-ahead):** one real registration through the driver with the lab Brain's stored sandbox credentials (the run overrode `registrar_env` to `sandbox` in its own process only — the lab document says `production` and was not changed). A throwaway `.com`:
  - `quote()` 17.99 / renewal 19.99, not premium; `register()` → registered, order 2146061, expires one year on; **the same call repeated with the same key returned the same order** (the idempotency header works, no second purchase);
  - `getDomain()` showed the Name.com nameservers, auto-renew on, locked; `setAutoRenew(off)` true; `renew()` → registered, order 2146062, expiry moved a further year;
  - `setNameservers` to the platform's own `ns1/ns2.appfactory.studio` → 200 (arbitrary hosts such as `ns1.example.net` are refused: "ns not found"); `lock(true)` true;
  - **`lock(false)` is refused** — "Domain can not be unlocked until" 60 days after registration (the registry's transfer lock). The driver correctly answers false; anything that needs an unlock right after a purchase (transfer-out) must wait for that date.
  - The domain's contacts were the Name.com account's defaults, which confirms the platform is the registrant (D6, for now).
- **Not verified:** production credentials were not used; the v1 `getPricing` answer shape for a registry-premium name was read from the spec, not exercised (the sandbox name was not premium).

## E11 — the browser builds the invoice with the Direct Payments SDK; the Brain only verifies (2026-10-09)

Done at the owner's instruction: Direct Payments is an already-built API, and the purchase must be implemented the way the playground samples (`w/www/public/playground/externals/direct-payments/`, `server/README.md`, the ecommerce façade's `attach` / `complete` / reconcile) do it. Steps 05 and 08 had departed from it: the Brain wrote the invoice itself in PHP and the page loaded it with `loadPreparedInvoice()`.

- **Brain (PHP, `w/www`):**
  - `PurchaseBilling::createInvoice` and `DirectPaymentsBilling::createInvoice` were **removed**. The interface is now `status(invoiceRef, directPaymentRef)` and `refund()`; `DirectPaymentsBilling` creates nothing and asks `PaymentGateway::snapshot`; an invoice that is not on the Direct Payment the browser named is "no such invoice".
  - `DomainPurchaseService::intent()` takes the name lock and returns the server's price, with `invoice_ref` null. New `attachInvoice()` (route `POST /purchases/{ref}/invoice`, member-scoped) records **the three references the samples name — invoice, Direct Payment, processor** — which the browser sends (new columns `direct_payment_ref`, `processor_ref` on `brain_domain_purchases`, migration `2026_10_09_000001`). One invoice belongs to one purchase; a replacement is accepted only while the previous one is unpaid. `stepPayment` treats "no invoice yet" as not paid, and the purchase is abandoned in time as before. The paid amount must still equal the Brain's price, otherwise the payment is refunded and nothing is registered.
  - **No Brain environment setting is involved**: the Direct Payment comes from the browser, as in the samples. (Earlier the same day a `config('brain.domains_direct_payment')` / `BRAIN_DOMAINS_DIRECT_PAYMENT` key and, before it, a Brain setting `purchase_direct_payment_ref` were tried; both are gone.)
- **Members (TypeScript + PHP, `w/members`):** `purchase.ts` has one `buildInvoice()` (initialise the Direct Payment, find the purchase item by SKU, `createInvoice`, `addItem({item_ref, quantity: 1, amount})`, `lock`) used by the old flow and the Brain flow; `initSurfaceInvoice` no longer uses `loadPreparedInvoice`. The Brain flow passes the Brain quote's amount and calls the new `attachInvoice` with the invoice reference, `getDirectPaymentId()` and `getStoredProcessorId()` (`surface.ts`, `surfaceViews.ts`; PHP proxy `DomainSurfaceController::purchaseInvoice`, route `POST /purchases/{ref}/invoice`).
- **Tests:** written for attach scoping, idempotency and replacement, the three references being kept and required, a purchase with no invoice, an invoice on another Direct Payment never being paid, the surface route, and `DirectPaymentsBillingTest`. **They have not been run since the last edit** (the owner asked to leave testing for later); an earlier run, before the three references were added, passed 54 tests / 411 assertions. The members TypeScript had no type errors in `controllers/domains` before the three-reference edit; not re-checked.
- **Not verified:** a charge against a real processor; the lab Brain VM still runs the old code and does not have the new migration. The recovery path for a tab closed after paying is the saga polling the attached invoice (like the ecommerce reconcile command); a payment whose invoice was never attached cannot be matched to a purchase.
- **Not changed:** the wider Direct Payments rules (server-priced lines, status only from the provider) are in `plans/www/direct_payments/TODOS.md`.

## E12 — Settings cut from 99 to 21; the rest is built in (2026-10-23)

The owner's rule: every server is a Linux CentOS/RHEL host, so a command, a service name, an install path, a SOA timer, a limit or a tuning number is not a setting. Only what differs between environments is.

* **Code (`w/www`):** new `app/Services/Brain/Domains/DomainDefaults.php` holds the built-in values; `DomainSettings::get()` reads the settings first and then it (a whole group such as `soa` or `selection` is merged over the built-in rest); `DomainSettings::only()` drops a stale document's values for keys that are now built in. `DomainSettings::KEYS` is now 20 keys (21 until `deploys_enabled` was removed the same day, see below) (`env`; `claims_enabled`, `publish_enabled`, `purchases_enabled`, `new_flow.all`, `new_flow.members`; `platform_ns`, `reserved_names`, `extra_allowed_suffixes`, `sites_zone`, `platform_zone_parent`, `hostmaster`, `parking_ipv4`, `allowed_server_cidrs`; `registrar_driver`, `registrar_env`; `storage_agent.brain_url`; `deploy.template_url`, `template_ref`, `template_token`).
* **Removed outright:** `reserved_suffixes` (`reserved_names` covers the name and anything under it), `deploy.env` (the template's `.env` supplies its values), `deploy.sites_cert` and `deploy.sites_cert_key` (the deploy step reads `tenant_cert` / `tenant_cert_key` from `controller.letsencrypt_cert_management`).
* **`controller.letsencrypt_cert_management.sites_cert`:** 19 keys down to `enabled`, `staging`, `email`, `zone`; the commands, paths, ssh values and the 30 / 14 day renewal and alarm days are built in (`SitesCertSettings::value()`).
* **Editor schema** `brain_controller_schema.ts` and the **seed row** in `testdb_appfactorystudio.data-only.sql` (`sites_cert` trimmed to the four keys) match.
* **Tests changed:** `DomainSettingsFixture` (the 14), `DomainSettingsTest` (+2 tests), `SitesCertTest` (+1), `DeployPipelineTest` (the wildcard now comes from the settings document). Run on MariaDB: `tests/Feature/Brain/Domains` plus `SitesCertTest` and `BrainStorageConnectTest`: 327 tests, one failure (the https deploy test, which read the removed `deploy.sites_cert`); I fixed that test and re-ran only the Deploy folder and `SitesCertTest`: 83 tests, OK. The rest of the 327 were not re-run after that fix.
* **Not done:** the `controller.domains` block is still not in the seed ([13 §13.2](../13-turning-it-on.md)).

## E13 — `deploys_enabled` removed (2026-10-23)

The website-build kill switch was copied from the new-way plan's pattern of one switch per feature. Its only reader was `DeploymentService::accept` (the Brain's own deployer, which is not used: tenant websites are deployed the old way), and the old way never read it. Removed at the owner's instruction: the setting, its check in `DeploymentService`, the editor field, the seed value, the fixture and the two tests that pinned it (`DOM_E_DISABLED` for deploys). `claims_enabled`, `publish_enabled` and `purchases_enabled` stay: they guard flows that are in use. `DomainSettingsTest` now uses `claims_enabled` for its "a missing setting stops the operation" case.

## E14 — the registrar and the environment are not settings (2026-10-23)

Owner: the system does not decide development or production from its settings. The only thing that does is `VITE_SUBDOMAIN` in `.env`: `www15` is development, `www` is production, and a missing value is production. The registrar in use is `controller.domain_registrar`, and its credentials are the entry of `controller.keys` whose `name` is that registrar, in its `development` or `production` slot, picked at runtime.

* **Removed settings:** `registrar_driver` and `registrar_env` (`DomainSettings::KEYS` is now 18), with their editor fields, seed values and fixture rows.
* **New (`w/www`):** `config/app_environment.php` and `App\Support\AppEnvironment` (`name()` is `development` or `production`), the same rule as `members`. `RegistrarCredentials::selected()` reads `controller.domain_registrar` (it stops and names the setting if it is not set); `RegistrarCredentials::get()` takes `development` or `production` (the old `sandbox` alias is gone).
* **Changed:** `NameComDriver` takes the credential slot and the base URL from `AppEnvironment` (the built-in URL key `namecom_base_url_sandbox` is now `namecom_base_url_development`); the container binds `RegistrarDriver` by `selected()` (`namecom`). The `fake` driver is no longer selectable by a setting: tests bind it directly, as they already did.
* **`env`:** see E15.

## E15 — no Vagrant lab: the `env` setting and the lab seeder removed (2026-10-24)

Owner: work is on the remote dev server; the lab was local and could not reach the internet, so it is not referenced. Dev and production come only from `VITE_SUBDOMAIN` (E14), never from a setting.

* **Removed:** the `env` setting (`DomainSettings::KEYS` is now 17), with its editor field, seed value and fixture row; the `domains:seed-lab` command (`DomainsSeedLab`) and its tests.
* **`DnsGuard`:** the rule "an allowlist is mandatory when `env` is set" is gone. `allowed_server_cidrs` still restricts which server addresses the Brain will contact when it is filled in; empty means no restriction. `SeedLabAndFleetGuardTest` became `FleetGuardTest` and keeps the guard tests.
* **Still in the repository and not removed:** `Lab*Test` / `DeployAgentLabTest` (real-VM tests, skipped without the VMs), `09-lab2-environment.md` and its report, `_env/lab2/.env`, and the in-memory `fake` registrar and test doubles used by the tests. Removing the first three is the owner's call.

## E16 — `claims_enabled` removed (2026-10-24)

The "Adding domains on" kill switch was read in one place (`DomainClaimService::create`). Owner: adding domains should never be turned off, so it is not a setting. Removed: the setting (`DomainSettings::KEYS` is now 16), its check, its editor field, seed value, fixture row and the test that pinned the `DOM_E_DISABLED` refusal. `publish_enabled` and `purchases_enabled` remain. `DomainSettingsTest` now uses `reserved_names` and `purchases_enabled` for its missing, empty and wrong-type cases.

## E17 — `publish_enabled` removed (2026-10-24)

Publishing DNS is part of the system, not an option. Removed: the setting (`DomainSettings::KEYS` is now 15), its checks in `DnsPublisher::runDue`, `DnsReconciler::run`, `domains:publish` and the two skip points in `DeployRunner`, its editor field, seed value and fixture row, and the `disabled` result that only it produced. A publish job now runs as soon as the scheduler picks it up; `allowed_server_cidrs` still blocks a run while an active server is outside the allowed networks. `DnsPublishingTest` no longer has a "switched off" case. The only switch left is `purchases_enabled`.

## E18 — `purchases_enabled` removed; no feature switches are left (2026-10-24)

Owner: buying domains is always on. Removed: the setting (`DomainSettings::KEYS` is now 14), its check at the start of `DomainPurchaseService::intent`, its editor field, seed value and fixture row, and the test case that pinned the `DOM_E_DISABLED` refusal. What remains of the 14 is what differs between environments (names, addresses, the allowed server networks, the template and the storage Brain URL) and the cut-over lists `new_flow.all` / `new_flow.members`. The purchase flow's own protections stay: the daily caps, the idempotency key, the payment check, and the quote. `DomainSettingsTest` uses `new_flow.all` for its bool cases.

## E19 — no domain settings, one flow, no guard (2026-10-24)

Owner: the application has not been published, there are no domains and no old ways, and 99 (then 14) settings were pointless. Everything below supersedes E7, E12 and the settings parts of E13 to E18.

* **Settings:** none. `controller.domains` does not exist; `DomainSettings` is only `get()`; every value is in `DomainDefaults` (the zone `sites.appfactory.studio`, name servers, reserved names, the template values, commands, paths, SOA timers, limits, tuning). Removed with it: `domains:settings`, the boot-time load, the `InfraConfigRules` domains checks, the editor schema block and the seed block, `DomainSettingsFixture`.
* **One flow:** `new_flow.all` / `new_flow.members`, `DomainFlow`, `DomainFlowController`, the two flow middlewares, the Brain `domains/flow` endpoint and the old `DomainController` domain methods are removed (E12's middleware and the 410 routes with them). The website build (`createWebsite`) stays.
* **Guard:** `allowed_server_cidrs` and the whole `DnsGuard` (publish, reconcile, both ssh transports, deploy acceptance, storage connect) are gone; so are the `blocked` results.
* **Certificate:** `letsencrypt_cert_management` keeps `tenant_cert` and `tenant_cert_key`; the rest is built in and Let's Encrypt staging follows `VITE_SUBDOMAIN`.
* **Slip, fixed:** an earlier `git checkout` of `brain_controller_schema.ts` had discarded the (uncommitted) nesting of `letsencrypt_cert_management`; restored.

## E20 — the wildcard certificate has no staging and no environment (2026-10-24)

Owner: with Let's Encrypt there is no development or production; everything is production because the certificate has to just work. Removed from `w/www`: the `staging` value and the separate `sites-wildcard-staging` lineage, the `--server` staging URL in `ProcessCertbotClient`, the "staging is on, nothing is sent" branches of `SitesCertRenewer`, `sites-cert:distribute` and `sites-cert:status`, and the `enabled` switch (the run is always on). E14's rule that `AppEnvironment` picks the registrar slot is unchanged; it is not used for the certificate. The staging test was replaced by one that pins a single lineage in every environment. `SitesCertTest`: 14 tests pass.

## E21 — more than one wildcard: `--domain` on the certificate commands (2026-10-24)

The storage devices need `*.storage-device.<STORAGE_BASE_DOMAIN>` too (storage-sharding 02 §3, 08 §8; the dev nginx block for it pointed at a certificate that did not exist, which made `nginx -t` fail for the whole server). `sites-cert:renew`, `:distribute`, `:status` and the certbot hook `:challenge` take `--domain=<base domain>` (`*.<domain>`). Without it they do every wildcard the platform keeps (`SitesCertDomains::all()`: the sites zone, and `storage-device.<brain.storage.base_domain>` when `STORAGE_BASE_DOMAIN` is set).

* **Where it is written:** any domain other than the sites zone is installed on the web hosts at `/etc/letsencrypt/live/<domain>/fullchain.pem` and `privkey.pem`, which is where nginx and every Let's Encrypt tool look. The sites zone keeps `tenant_cert` / `tenant_cert_key`.
* **Own lineage and record:** certbot names the lineage after the domain, and the run keeps `state-<domain>.json`; the sites zone keeps `sites-wildcard` and `state.json`.
* **The challenge line is fully qualified** (`_acme-challenge.<domain>. 60 IN TXT "..." ; afs-acme`), so one include file serves any name under the zone.
* **Not done:** the pair goes to the active web hosts (`brain_http_servers`) only. A separate storage server (`brain_storage_servers`) is not a target yet; on dev they are the same machine.
* **Tests:** `SitesCertTest` 18 tests pass (domain settings and paths, refused domain names, the domains list, the hook naming its domain).

## E22 — a domain is handled by the registrar it was bought through (2026-10-24)

The registrar was already recorded at purchase (`brain_domain_purchases.registrar` and `registrar_order_ref`, and `brain_dns_zones.registrar` and `registrar_ref`), but every later call went to the one registrar in use now (`controller.domain_registrar`), so a switch would have sent renew, release and retry calls for old domains to the wrong registrar. New `RegistrarDrivers::for(?string $name)` finds the driver by the recorded name (the one in use if it matches, `namecom`, else `DOM_E_REGISTRAR_UNKNOWN`; nothing recorded means the one in use). `DomainPurchaseService` uses it for an existing purchase's `getDomain`, `register` and `setNameservers`; `DomainReleaseService` uses it to switch auto-renew off for a purchased claim, and a name whose registrar has no driver still gets its release scheduled with `registrar_auto_renew_off: false` in the event. Search, quote and a new purchase still use `controller.domain_registrar`. Adding a registrar: one driver class, one `match` entry in `RegistrarDrivers`, one `controller.keys` entry; the old registrar's `keys` entry must stay while its domains exist. `DomainPurchaseTest` +3 tests; `tests/Feature/Brain/Domains` 282 tests pass. A connected domain (not bought through us) has no registrar recorded.

## E23 — the zone is built with Badcow (2026-10-24)

`ZoneRenderer` now builds the records with `Badcow\DNS` (typed `Rdata\Factory`, `AlignedBuilder`) instead of formatting text by hand; the SOA block is still written by hand in the form the DNS agent reads, so the agent and the validator needed no change. The golden zone files in `tests/fixtures/domains/zones/` were regenerated (records are now grouped by type, a long TXT is several short strings in parentheses). Full account: step 16.
