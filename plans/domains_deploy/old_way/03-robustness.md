# 03 — Robustness (P0 / P1)

Same scripts, same JSON, same order of work. Each item is a finding from step 01, with the smallest change that fixes it. Items marked **confirm** change a value the tenant may read today; the owner confirms each before it is built (OD2).

## 3.1 The browser must not receive the secrets (P0, **OF16**)

`createWebsite` returns the Brain config, the DNS and server logins and the whole job object to the browser (`DomainController.php:413–426, 564–569`). The front end reads **only** `tracker_key` and `website_reference` (`resources/js/controllers/domains/api.ts:123`, `website.ts:144`).

Change: the response becomes `{success, tracker_key, website_reference}`. The removed keys (`website`, `tenant`, `http_server`, `zone`, `brain_config`, `brain_dns_*`, `brain_http_servers*`, `subdomain_properties`, `directories`) are dropped, not renamed. A test asserts none of the secrets' names or values appear in the response. Also remove `error_log`/debug output that prints the job, if any (checked while editing).

## 3.2 The script says when it failed (P0, **OF13**)

Today the script ends with `record_progress … "complete"` whatever happened. Add checks inside `app_install.sh` (and `commands.sh` where the step lives), each one failing the build with `record_progress … "failed" "<reason>"` and a non-zero exit; the final `complete` is only reached when all pass. `record_progress` calls for failures replace, not add to, the normal calls, so `max_steps` stays 19 on a normal run.

| Where | Check |
|---|---|
| before anything | `node -v`, `npm -v`, `composer -V`, `git --version`, `jq`, `curl`, free space on `/home` (≥ a number of GB set in the job), the MySQL root login works, the template and the tenant Gitea repo URLs answer |
| after `composer install` | `vendor/autoload.php` exists |
| after `migrate` and `db:seed` | `php artisan migrate:status` shows nothing pending; the exit codes of both are tested (today they are ignored) |
| after `npm install` and `npm run build` | `public/build/manifest.json` exists; the exit code is tested |
| after the `subdomain_properties` insert | the row count is 21 and `Username`, `SystemUser`, `db_name` are non-empty |
| after the vhosts are written | `apachectl -t` and `nginx -t` pass; if either fails the previous files are restored and the build fails (today a bad file would stop the server's other tenants at the next reload) |
| last | `curl -H 'Host: <each name>' http://127.0.0.1:808/` answers 2xx or 3xx for the member's name and the sites name (the template has no public `/health` yet; adding one is in [step 04](04-lab-and-template.md) §4.2) |

## 3.3 Safe to run again (P1, **OF13**)

Each action in `run_create_repo` and `make_storage_directories` becomes "do it unless it is already done", without changing what it does the first time:

- `useradd` only if `id <user>` fails; `mkdir -p` (already); the copy into `/home/<u>/websites/www` only if that directory is not already a finished build (a marker file `.afs-build-done` is written at the end of a good build).
- The database and user are already guarded by the existing `SCHEMA_NAME` test; `GRANT`/`FLUSH` are repeatable.
- The Gitea repo create: a 409 "already exists" is not an error; the push uses `-f` already.
- The vhost files: write to `<name>.new`, test, then move over the old file.
- The `subdomain_properties` insert: `DELETE FROM subdomain_properties WHERE SubDomain = '<key>'` and the `INSERT` go in one transaction, after checking the table's keys (`public/_portal_/system/db/*.sql`, the migrations) so a repeat does not duplicate the 21 rows.

The `members_websites` row and the Brain tenant record are written before the build today (`createWebsite` `:515–549`). Keep that order (the progress screen needs the website reference). When the **final status** reaches `ProgressController::recordProgress` (`app/Http/Controllers/Progress/ProgressController.php:25`) as `failed`, mark the website row `active = 0` with its json `status = failed`; as `completed`, `status = active`. Today that controller does not touch `members_websites` (checked), so a failed build leaves a row that looks live. **Confirm** (it changes what the list shows for a failed build).

## 3.4 Secrets in files and logs (P1 for production, **OF11**; the lab is exempt by the standing rule, the code is the same)

| Today | Change |
|---|---|
| the job folder `chmod -R 777`, file world-readable | folder `0700`, file `0600`, owned by the user the pipeline runs as (`commands.sh` `step1`; the `mkdir` in `createWebsite`) |
| the job folder is never removed | removed when the last status arrives (success or failure); a Brain-side or cron sweep removes folders older than a day |
| `echo "  password: …"` (`commands.sh:489`) | deleted |
| `echo "$GITEA_PASSWORD2"` (`app_install.sh:440`) | deleted; the push stays as it is (an `expect` typing the password) unless the owner wants a token header instead (OD7) |
| `mysql -p"${mysql_password}"` on a command line (visible in `ps`) | `--defaults-extra-file` with a `0600` file, removed afterwards |
| the whole Brain credential set in the job | trimmed to what the scripts read: first the inventory — `grep "jq -r '\." commands.sh remote/builder/app_install.sh`, the `expect` parameters in `commands.expect`, the reads in `background_runner.php` — written into this step as a table (key, read where, secret yes/no); anything unread is removed from the job (audit F27 in `new_way`) |

## 3.5 Values (**confirm** each; OF9, OF10, OF12)

These make the JSON and the 21 rows consistent. They do not change which keys exist.

| # | Today | Change | Why it is safe to ask |
|---|---|---|---|
| V1 | `reference_num` row = `openssl rand -hex 12` made in the script | use `tenant.reference_num` from the job (the value the Brain's tenant record has) | the tenant and the Brain then agree on the tenant's id |
| V2 | `CreatedBy` row inserts empty | `tenant.CreatedBy` (the Brain's `Subdomain`, which PHP already puts there) | the variable the script uses is never assigned |
| V3 | `owner` row = literal `www` | `tenant.owner`, **only after** step 04's reader check says what the template does with it; until then leave | PHP sends `false`; the template may depend on `www` |
| V4 | `db_password` row = PHP literal; the database user is created with another literal; same for every tenant | one generated password per tenant, made in `getTenantData` (`Str::random(24)` or the existing `Utils_generateRandomString`), used for `CREATE USER`, `.env` and the row | OF10 |
| V5 | `brain.systemuser/domain/subdomain`, `Username`, the sites zone are literals | read from the Brain data already in hand (the Brain's `subdomain_properties` for `Domain`, `SystemUser`; the sites zone from the Brain settings) | OF12; on the production Brain the values are the same |

V1, V2 and V4 are defects, not choices. V3 and V5 change nothing in production. Which `Subdomain` key a tenant should be filed under (the Brain's `www15` in the lab versus its own) is OD4 and is not changed here.

## 3.6 Progress (P1, **OF15**)

Keep the additive model. Two small additions: the final status call is retried three times with a pause when it fails (a tenant host that briefly cannot reach members), and a build with no status for longer than a configured time (default 90 minutes) is shown as `failed — no news from the server` by `getProgress`, not as running forever.

## 3.7 Work

| Phase | Work |
|---|---|
| **P0** | §3.1 (response trimmed, with its test); §3.2 checks; the secrets inventory table; V1, V2, V4 once confirmed |
| **P1** | §3.3 re-runnable actions and the website-row status; §3.4 permissions, removal, log lines, defaults file; V3, V5; §3.6 |
| **P2** | A per-build token in the job file that `/api/v1/progress/record` checks, so a stranger cannot move another member's progress bar |
