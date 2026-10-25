# old_way — off-plan work

## E1 — 2026-10-24 — The website screen builds through the old pipeline

**Found:** the "Create Website" button on the member's domain screen called the Brain's new deployer (`DomainSurfaceController::createWebsite` → Brain `domains/{ref}/websites`). Its template settings are empty, so every click was refused. The owner's chosen pipeline (`DomainController::createWebsite` → `background_runner.php` → `commands.sh` → `app_install.sh`) had no caller left in the screen.

**Done (`w/members`):**
* `surfaceViews.ts` `websiteView` rewritten: reads the member's `members_websites` rows (`GET /websites`); `json.status` `active` shows the manage section, `pending` re-attaches the progress widget to the stored `tracker_key`, anything else offers Create. Create posts `{domain_name}` to `POST /create-website`, then follows the tracker with the progress widget; when it finishes the rows are read again (a failed build shows the failure notice and the button again).
* `surface.ts`: `listWebsites()`, `createWebsite()` (own `callOwn`, since these routes answer `{success, ...}` not the Brain envelope); the Brain-deployer calls (`startWebsite`, `websiteStatus`, `websiteAction`, `deleteWebsite`) and `SurfaceDeployment` are gone.
* `DomainSurfaceController` lost its website methods and the `members_websites` mirror; the four `{ref}/website` routes are gone; three tests of them removed.
* Enable/disable and Delete Site are not offered: no script does either in this pipeline (the plan's A13, tenant removal, is still unwritten).

**Verified:** `DomainSurfaceControllerTest` + `DomainRoutesTest` 11 tests OK; `vitest` domains 8 passed; `tsc` members 62 errors, none in `controllers/domains/`.
**Not verified:** not seen in a browser; no real build run; nothing uploaded.

## E2 — 2026-10-25 — A built website is tied to its claim, so the zone and the sites list point at it

**Found:** a manual-add claim (`imminentimagination.com`) went through verification and was served by the platform's BIND, but the old build never connected its tenant row to the claim. The claim's zone got no apex address and a `www` CNAME to a sites name nothing served. The build also appended its own line to the sites include by hand, which the Brain's publisher (it writes the whole file from its database) refuses, or later silently drops, once the first-publish safeguard is off.

**Done (`w/www`):**
* `TenantLink` (new): at `build/tenant` the tenant row is tied to the claim that holds the name (`claim_ref`, the claim's `site_ref`, the server, state `provisioning`); `build/tenant-live` settles it (`completed` makes it `active` and calls `DomainDnsSync::siteChanged`; `failed` frees the slot). A `provisioning` build silent for 90 minutes gives up its slot when the next one starts (measured on the database clock: the row's `date` is stamped by it, and PHP's clock was hours off in the test environment).
* `BrainBuildSurfaceController`: `build/data` returns the claim; `build/tenant` answers 422 `NO_CLAIM` (a name this member does not hold) or 409 `WEBSITE_EXISTS` (checked in code under a row lock; the unique index is only a backstop, it is a rollout step and may not be on every server); new route `build/tenant-live`.
* The tenant model can hold the claim, site name, server and state.

**Done (`w/members`):**
* `DomainController`: the site's second name (`Domain2`, `Address2`, `Subdomain2`, the certificate paths) is the claim's own `<site_ref>.sites.<domain>`; the system user, database and directories keep their own name. No claim, no build. A refused `build/tenant` now stops the build (it was ignored) and removes the job file.
* `ProgressController`: when a build settles (completed, failed, or silent for 90 minutes) it tells the Brain through the signed surface; a call that fails is logged and undoes nothing.
* `app_install.sh` `build_dns`: the hand-appended include line is gone (the `named` reload stays).

**Also, in the same stretch:** `domains:publish` (every minute) and `domains:reverify` (every five) are scheduled from members and allowed on the Brain's command list (claims are the only domain path, so nothing else would publish their jobs); the DNS agent `afs-dns-agent` was installed on the dev server; the one DNS server row was moved to family 1; the two hand-written sites lines (`x1sgh1ybza3n5ct`, `xrnsqhootwbfphjcxvahmhb9`) were adopted into `brain_dns_subdomains`; the claim now gets a temporary proof zone (`Dns/ProofZone.php`) and the parent-zone delegation is read when public resolvers answer SERVFAIL (a lame delegation).

**Verified:** `w/www` `BrainBuildSurfaceTest` 16 tests (114 assertions); `w/members` `WebsiteBuildOutcomeTest`, `ProgressTokenTest`, `DomainBrainSurfaceTest` 39 tests (161 assertions).
**Not verified:** no real build run since; the Brain call from members was only tested with a fake client. Uploaded to the dev server 2026-10-25 (7 files); not committed.

## E3 — 2026-10-25 — A wipe and re-install keeps the domain-name set-up (`build_sites2.sh` step 7b)

**Found:** getting the first domain working on the dev server needed three things done by hand that live in no repository, and `build_sites2.sh` (wipe, clone, `use-env.sh`, data import) undoes or never makes all of them: the DNS agent on the DNS server (`/usr/local/bin/afs-dns-agent`, `/var/named/.staging`, `.trash`), the DNS server's family (the data import carries the row with family 0; claims are created in family 1, so the first zone failed with "no active DNS server in family 1"), and the two hand-written lines of the sites include (the publisher refused its first sites publish rather than drop them).

**Done:**
* `bin/install-dns.sh` (new): for every active row of `brain_dns_servers`, installs the agent and its directories (directly when the server is this machine, over ssh with the stored login otherwise, through `_ssh_pw.exp` / `_ssh_run.exp`, password in the helper's environment only), checks `named-checkconf`, then runs `domains:prepare-dns` with a copy of that server's sites include. It does not edit `named.conf` or any zone.
* `w/www` `domains:prepare-dns` (`DomainsPrepareDns`, `Dns/DnsServerPrep`): family 0 becomes 1 (any other family is left alone); every `<name> IN A <ip>` line of the sites include that the database lacks is adopted into `brain_dns_subdomains` (no claim, no member), lines that are not plain A records are reported; `--dry-run`, `--include-file`, and `--list` (the include path and each active server's login, printed raw because the installer splits it on tabs).
* `build_sites2.sh`: step 7b runs `install-dns.sh --www-dir $DEV_ROOT/www` after the afs-website step and before the checks and the swap, on every install and every update, as root; a missing script, a non-root run, or a failed server is a recorded failed step. Header, `--help`, topics (`--help domains`) and the prod notes are updated.

**Verified:** `DnsServerPrepTest` 10 tests (46 assertions); `bash -n` on both scripts; `--help domains` renders; the tab-separated list parses in the installer's loop with a password containing `<`, `$` and a space.
**Not verified:** `install-dns.sh` has not been run (not even `--dry-run`); the ssh path to a DNS server that is another machine has never run; `build_sites2.sh` has not been run since the change. The scripts are not on the server, and the code is not committed or pushed (the install clones from GitHub).
