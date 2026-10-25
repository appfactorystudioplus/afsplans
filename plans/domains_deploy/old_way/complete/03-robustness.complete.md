# 03-robustness — completion record

**Plan file:** [`../03-robustness.md`](../03-robustness.md)
**Repo:** `w/members` (code and tests)
**Date:** 2026-10-22
**Status:** 🟡 P0, P1 and P2 of this step are in code; the parts that run on a server (`commands.sh`, `commands.expect`, `app_install.sh`) are checked for syntax and, where possible, by direct runs, **not by a real build**. Nothing deployed, nothing committed.

## Where each section stands

| § | Item | State |
|---|---|---|
| 3.1 | Browser gets only `success, tracker_key, website_reference` | Done 2026-10-15 (plan 11). |
| 3.2 | The script says when it failed | Done 2026-10-15 for tools, disk, MySQL, repos, composer, migrate, npm, manifest, 21 rows, `apachectl`/`nginx -t`, site answers. **Added today:** host and system names validated; Git repository create (HTTP 201/409), clone and push checked; wrapper compile checked; `g++` in the tool list; the `ssh` exit status and a silent-build timeout (below). |
| 3.3 | Safe to run again, failed website off | Done 2026-10-15 (marker, guarded user/db, transaction). **Added today:** one website per domain at a time (409). Retrying still builds a *new* tenant (the system user is random per press), so the re-run guards help only inside one build. |
| 3.4 | Secrets in files and logs | Done 2026-10-15 (0700/0600, removal, options file). **Added today:** the Git token and the members repository address are no longer written to the log; the clone's output is not logged; the job folder is removed on every exit of `commands.sh` (a failed step included); the copy on each DNS server is removed; `exp_internal 1` (printed the password) removed; job trimmed to what scripts read (inventory below). |
| 3.5 | V1–V5 | Done 2026-10-15 (V1, V2, V3 as job value, V4, V5). |
| 3.6 | Progress | Retries and the 90-minute rule done 2026-10-15. **Added today:** `max_steps` follows the number of DNS servers; an unknown tracker is refused; the website settled is the tracker's own; the page polls 90 minutes, not 6. |

## What this session changed (`w/members`)

* **Host name validation.** `DomainController::HOSTNAME_PATTERN`, used by `createWebsite` and `store`; the name is lower-cased. `build_checks.sh` has the same rule (`check_hostname`) and `app_install.sh` stops the build if the member domain or sites name, the tenant's system name or the Brain's subdomain is not plain. Before, a zone named with a quote reached `mysql` as root.
* **One website per domain.** `createWebsite` answers 409 while the member has a live site, or a pending one younger than 90 minutes, on the domain. It runs after the Brain checks and before anything is written.
* **Job inventory and trim.** Removed from the job file: `github`, `zone`, `brain.ipv4/username/password/remote_dir/brain_pass/brain_host/portal_root_password/portal_admin_password` (no script reads them; dev has no Brain-type server either).
* **Failure no longer silent.** `commands.sh` checks the result of each DNS server and of the tenant build. `build_fail` exits **3** meaning "recorded, with the reason"; any other non-zero (ssh refused, dropped, two hours of silence) is posted as `failed` by `commands.sh`. `app_install.sh build_all` ends `exit 0`, so a progress post cannot decide the status.
* **Builds longer than five minutes.** `commands.expect` ended the connection after 300 s without terminal output, and the build prints almost nothing there, so a long `composer`/`npm` stretch killed the session and the build with it. The build session now waits two hours and sends keep-alives.
* **One machine as members, DNS and tenant host (dev today).** `scp -r` of the job folder onto itself empties the files it reads; `commands.sh` sets `AFS_JOB_LOCAL=1` when the target is one of this machine's own addresses and `commands.expect` skips the copy (and the clean-up of the remote copy).
* **Progress.** `ProgressController` refuses a tracker with no state (404). Before, a tracker without a stored token accepted anyone, and the website to turn off came from the request body. `max_steps = 17 + number of DNS servers` (was 19; the fourth DNS server pushed the bar to 100% mid-build). The widget polls 1800 × 3 s.

## Secrets inventory (what the scripts read from the job)

| Key | Read by | Secret |
|---|---|---|
| `tenant.*` (names, `db_password`, certificate paths) | `app_install.sh` | `db_password` |
| `managed_domain.*` (Brain's `subdomain_properties`) | `app_install.sh`, `commands.sh` | no |
| `git_settings.*` | `app_install.sh` | tokens, passwords, repository addresses |
| `dns[].ipv4/username/password/hostname/active` | `commands.sh`, `app_install.sh` | `password` |
| `server.public_ipv4`, `server.json.users[0]` | `commands.sh` | root login of the tenant host |
| `brain.systemuser/domain/subdomain/api_url/mysql_password`, `member_reference_num` | `app_install.sh`, `commands.sh` | `mysql_password` |
| `progress.*` | `record_progress` | `token` |
| `website.reference_num`, `letsencrypt_check`, `min_free_gb`, `root_directory` | scripts, `background_runner.php` | no |

## How it was checked

* `vendor/bin/phpunit` on **only** the files touched: `DomainBrainSurfaceTest` (+1 changed), `ProgressTokenTest` (+3), `WebsiteBuildOutcomeTest` (+3; on the local MariaDB, stopped afterwards) — together **38 tests, all OK**. New checks: hostname pattern (valid and 15 hostile names), one website per domain (building, other member, other domain, quiet 3 h, live, failed), tracker settlement (a build holding the right token naming another member's website changes nothing), unknown tracker 404, step count.
* `bash -n` on `commands.sh`, `app_install.sh`, `build_checks.sh`; `check_hostname`, `check_system_name`, `check_http_code` and `build_fail`'s exit 3 run directly in bash with good and hostile input.
* `commands.expect`: braces balance (303/303); no Tcl interpreter on this PC, so the edits are **unrun**.
* `BuildChecksShellTest` (10 + 2 new) cannot run on this PC: it calls `/bin/bash` through PHP `exec()`, which Windows does not resolve (same failure for the tests I did not touch). Run it where `/bin/bash` exists.

## Not verified

* A real build: the new Git create/clone/push checks, the `expect` changes (timeout, exit status, local skip, remote clean-up) and the DNS result check only run on a server. The `expect -c` push script now ends `catch wait result; exit [lindex $result 3]`.
* Retrying a failed build reuses nothing; a failed build leaves its Linux user, database, Git repository and `/mnt` directories (no clean-up script yet — step 04 §4.6 A13 lists the removal tooling).
* The mail setup (hard-coded `no-reply` password, Dovecot files overwritten per tenant) and the nginx `/portal/api` CORS block are outside this step; carried to step 07 as questions.
