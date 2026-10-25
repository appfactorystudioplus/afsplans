# 09 — lab2 environment: completion report (website deployment)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **website deployment** half; the **domain name management** half is [../../domain_name_management/complete/09-lab2-environment.complete.md](../../domain_name_management/complete/09-lab2-environment.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: P0 built except the pieces that need the Brain code on the lab Brain VM (the worker and scheduler are written, tested as a dry run, and NOT installed). Acceptance 09 §9.9 items 1–4 are proven on the real lab; items 5–8 are proven on the real web VM with a stand-in template and a fake registrar; item 9 is covered by the step 02/04 tests; item 10 (move) is step 12 and not built.**
> Carried out 2026-10-09 against [09-lab2-environment.md](../09-lab2-environment.md). Nothing is committed. Code is in `w/www` and `C:\projects\vagrant\lab2`.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

| Plan section | Built as |
|---|---|
| §9.6 tenant host | Agent: step 07. **New:** `lab2/web/install-parking.sh` — a catch-all: `<anything>.sites.test2` with no website yet → the parking page (200); any other unknown Host → 404; the file is tested with `nginx -t` before the reload and restored on failure. Installed on the running web VM; `provision/tenant-host-setup.sh` runs it. Server initiation (node/npm/tokens) is the owner's block in `initial_install.sh` and was not touched |
| §9.7 Brain VM | `lab2/provision/brain-worker.sh` — a systemd unit for the `deploy` queue worker and `/etc/cron.d/afs-schedule` (`schedule:run` every minute); `--dry-run` prints both; it installs nothing unless the app directory has an `artisan`. **Not installed** (see §4) |

(§9.1 the seeder and guard, §9.2 the settings, §9.4 the customer-DNS harness, §9.5 the DNS VM and §9.8 the registrar are in the domain-name report.)

## 2. Files

The seeder, guard, DNS agent and DNS harness files are listed in the domain-name report §2: [../../domain_name_management/complete/09-lab2-environment.complete.md](../../domain_name_management/complete/09-lab2-environment.complete.md). The website half: `app/Services/Brain/Deploy/DeploymentService.php` (the fleet check, `DOM_E_FLEET_UNSAFE`), test `Deploy/LabDeployTest` (parking assertions); in `C:\projects\vagrant\lab2`: `web/install-parking.sh`, `provision/tenant-host-setup.sh`, `provision/brain-worker.sh`.

## 3. Acceptance, item by item (09 §9.9)


| # | Item | Result |
|---|---|---|
| 5 | *Create website*: server chosen (explanation), 100 %, both names serve the same site, no certbot | **Proven on the real web VM with a stand-in template** (`LabDeployTest`): selection recorded with its explanation, the pipeline to 100 %, **both names answer through nginx :80 → Apache :808 with the same release and no redirect**, an unknown name is 404 and a sites name with no website is the parking page. **Not proven:** the real template, HTTPS on the sites name (no certificate configured in the test), `www.<apex>` over HTTP by `curl`, browser use |
| 6 | Kill the worker mid-deploy → it resumes; cancel mid-deploy → nothing left | **Proven with the in-memory host** (`DeployPipelineTest`: stale-heartbeat takeover resumes without starting over; cancel after each kind of failure removes the tenant, gives the slot back, restores DNS). **Not proven on the real VM:** killing a real process mid-install |
| 8 | Delete the website: names stop, claim and zone remain | **Proven on the real VM** (`LabDeployTest`: user, database and vhost gone; the claim stays); that the names then answer the parking page is by the catch-all above, not asserted in that test |
| 10 | Move a site to a second host | **Not built** (step 12) |

## 4. What the lab turned out to be (checked, not assumed)

- **The stored lab logins work.** Using the exact passwords in the lab database's server records, the real runner (Brain VM → DNS VM, Brain VM → web VM, as `root`) ran `afs-dns-agent version` and `afs-tenant-agent ping` — so, once the code is on the Brain VM, the Brain can reach both servers with no key. (`PermitRootLogin yes` and `PasswordAuthentication yes` are on all three VMs.)
- **The Brain code on the lab Brain VM is the old code** (`/home/appfactorystudio/websites/www`): none of steps 02–10 is there. That is why the worker script was not installed and why nothing in this report was run *on* the Brain VM except the ssh runner. Putting the code there is the next real step.
- **The unknown-Host behaviour of the web VM was a redirect to `https://web.test2`**, not a catch-all; now it is.

## 5. Where the build differs from the plan text

4\. **The boot-time fleet check** is enforced where it matters (the publisher's `runDue` and the deploy `accept`) instead of in a service provider, so a bad fleet stops the operation and says so rather than silently disabling features at boot.
5\. **`deploy-tenant.sh` is not retired** (P1, and the owner's Path A may still use it); `add-zone.sh`/`deploy-tenant.sh` still append to the sites include, which the Brain's adopt mode tolerates (step 04).

## 6. Verification

Real MariaDB and the real lab VMs. One run of the whole Domains folder + the migration guardrail + the config-rules tests with all opt-in lab tests switched on: **272 tests, 3,897 assertions; 270 passed in that run.** The two that failed were both lab-DNS tests and the cause was a defect in the NEW `LabConnectFlowTest`: it left its line in the shared sites list on the DNS VM, and the next test's first publish (adopt mode, which refuses to drop a line it did not write) failed. The test now releases its claim at the end (which also proves 09 item 9 through the flow) and its tear-down removes its line; the stale line was removed, and the three lab-DNS runs (connect, publish, connect again, in that order) then passed: 3 tests, 71 assertions, and the sites list is unchanged afterwards. The whole folder was **not** run a second time after that fix.

## 7. Not verified, honestly

- Anything running **on** the lab Brain VM: the code, the worker, the scheduler, `domains:*` commands there.
- The real template, HTTPS on the sites name, killing a real process mid-install, a browser.
- A **production** DNS or web server (the guard, the seeder and the harness are lab-only or inert there).
- The Vagrant provisioners (`dns.sh`, `tenant-host-setup.sh`, `brain-worker.sh`) were checked with `bash -n` and, for the installers, by running their scripts on the running VMs; a **fresh `vagrant up`** was not run.

## 8. To do next, in order

1\. **Sync the Brain code to the lab Brain VM** (the owner's sync), run `domains:settings`, `domains:seed-lab --dry-run`, `provision/brain-worker.sh`, and repeat acceptance items 5–6 from the Brain VM itself.
2\. Install node/npm on the lab web VM and pin the template (`deploy.template_ref`) to run items 5 and 8 with the real template and with HTTPS on the sites name.
3\. Add the ssh logins to any server row the seeder created.
4\. Step 12 for item 10.
