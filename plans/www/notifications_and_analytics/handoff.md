# Handoff

> **A rolling continuation file, not a stable reference.** It says what was done, how to work in this estate, what is verified and what is not, and what to pick up next.
> It is expected to be overwritten as work moves on. Do not cite it by section number from a numbered plan; a fact worth keeping belongs in its plan document.
>
> **This version replaces the 2026-10-09 handoff** (domains deploy, Direct Payments review, externals namespace and security). That file is in git:
> `git -C C:\projects\vagrant\afs-eco\packages\afsserver show dbcb2d97:plans/handoff.md`. What from it is still useful is condensed in §13.
>
> **Secrets are deliberately not in this file.** Where a credential lives is named; its value never is. Do not paste one into a plan, a chat or a commit.

**Written 2026-10-19.** (The harness date is 2026-10-19; this PC's clock showed the evening of 2026-10-18 CDT while the work ran.) The conversation for the part of the work after the
Direct Payments audit steps 09/10 is `C:\Users\mrmit\.claude\projects\C--projects\efb40551-75a8-492e-a5d4-c3770bbf5f41.jsonl`; session scratch files (tools, logs, patch scripts) are in
`C:\Users\mrmit\AppData\Local\Temp\claude\C--projects\efb40551-75a8-492e-a5d4-c3770bbf5f41\scratchpad\` — temporary, in no repository.

---

## 0. In one page

| | |
|---|---|
| **What the owner is finishing** | (1) the **proper creation of a member** — signup through the Direct Payments API; (2) the **proper handling of payments**; (3) **sending e-mails** when things happen (a payment, a signup); (4) the **deployment of websites**. The two projects: `C:\projects\vagrant\afs-eco\w\www` (the Brain) and `C:\projects\vagrant\afs-eco\w\members` (the members dashboard). The tools that build and operate the server: `C:\projects\vagrant\afs-eco\packages\afsserver\bin` |
| **What got built this session** | The whole **Direct Payments audit** plan (`plans/www/direct_payments_audit`, steps 00–16, completion record per step) — processor lock-in, server routing, payer/customer identity, a swap engine, subscription legs and migration, extension points, naming separation, default e-mails; the member **signup** hardened (`SubscriptionClaim`, a welcome-email failure no longer breaks signup); **import-ready Direct Payments rows** in the clean-install SQL; **`build_sites2.sh`** (the fresh-install and update script) grew `desktop_updates`, ownership, the scheduler as the last action, `--update-repos`, `--deploy-type dev|prod`, `--no-db-import`, a full `--help`, `--install-ecosystem`, `--destroy-db`; **`laravel-cron.sh`** can stop/start/remove entries and never adds one twice; **`remote-run.sh`** reaches the dev server with a passphrase key or a password fallback; the **`app()` helper was removed** from `w/www/app` (118 of 129 calls); a **plan** for notifications + analytics + a data-cleanup job was written (`plans/www/notifications_and_analytics`, nothing built) |
| **Where the dev server stands** | A fresh install (`build_sites2.sh --install-ecosystem --destroy-db --yes`) **ran on 2026-10-19** on `45.79.42.137`. Everything migrated and built; the data-only SQL was imported (Direct Payments, processors, tiers, one subscription); the scheduler line for `www` and `members` is in the `apache` crontab; `desktop_updates` is installed with its releases folder. Only `storage_device` had failing steps (the script wrongly expected a seeder and a front end — fixed in the script, not re-run). Details §6.7 |
| **What has NOT been verified anywhere** | A **real payment** (a sandbox Stripe charge → webhook → receipt e-mail); a **real e-mail send**; any **browser** flow (signup page, renewal page, admin screens, the bell); the Direct Payments **SDK posting the provider subscription id** (the server maps it, but the SDK was not changed); real provider sandboxes for PayPal and Square; the e-mail templates in Gmail/Outlook. The dev server runs this code now, so these can be tried — by the owner or with the owner's say-so (§6.6) |
| **Uncommitted** | `w/www`: one hunk in `MembershipController.php` that is **the owner's** (it comments out the `amount`/`brand`/`last4` lookups and sets them to `""` — leave it alone). `packages/afsserver`: the new folder `plans/www/notifications_and_analytics/` and this file. Everything else from this session is committed (§11) |
| **Next, in order** | §12: (1) try a sandbox payment end to end on dev using §7.9; (2) answer the open questions in the notifications plan (OQ3, OQ7, OQ12, OQ13 first); (3) switch the SDK/signup page to send the `sub_…` reference; (4) the G2/G7 clean-ups; (5) adopt the notification system module by module |

---

## 1. How to work with the owner

The full text of every standing instruction is in **Appendix A** (all memory files, verbatim). The short version, most important first:

- **Answer the question first, in plain language; name the language and the file.** Say PHP (Brain, `w/www`) or TypeScript (members, `w/members`) and give a clickable path. No invented vocabulary. No lecture about "the browser is untrusted".
- **"Show me" means show the code.** Paste the real code, in execution order, with `path:line`. A diagram or a summary was rejected twice in this session (the owner was angry about it: "I want to see the code"). Read the current file first; it may have changed.
- **Never run anything on the remote server unless the owner says so, and say each command first.** In this session the owner later said "you have full access, just let me know everything you do" for the install run — that is a per-task grant, not a standing one. List every command and what it changes.
- **Never commit, branch or push on your own.** Commit only when told, one commit per repo, with the attribution line from the system reminder; stage explicit paths. The owner often says "commit everything" after a block of work.
- **Run tests at the end of big work, one heavy process at a time, with progress visible** (background log, never `--no-progress`). The PC froze twice from parallel heavy jobs. Kill what you start; check the process table before saying done.
- **No `app()` helper** for service location; inject through the constructor, `handle()` or method parameters (§10).
- **Test against MySQL/MariaDB, never rewrite code for SQLite.** `phpunit.xml` points at a lab database that is usually down — always pass `DB_*` explicitly (§3).
- **Never install** tools or packages to test your own work; use stubs and let the owner run the real install.
- **Never log in as the agent** (no typing credentials into forms, no resetting passwords) — even for local seed accounts.
- **Never `config:cache`; never run `use-env.sh` automatically.** Warn about a mismatched `.env`, do not switch it.
- **Plans only live in `packages/afsserver/plans`.** Vocabulary: a *step* is a numbered plan file; a *phase* is work inside it (`P0`, `P1`…); never "unit". Completion docs: `complete/<NN>-<same name as the plan>.complete.md`, one per step, written when the step is finished; off-plan work goes in `complete/00_extras.md` as dated `E<n>`.
- **Lab security is not a concern** (the Vagrant labs are disposable) — do not raise warnings about lab keys or open ports. That does **not** cover real credentials: never copy them into documents.
- **Windows/Git Bash traps:** heredocs eat backslashes — write regex/escape-heavy files with the Write or Edit tool and verify the backslashes survived (§3.9).

---

## 2. The estate and this machine

- Root `C:\projects\vagrant\afs-eco`. **`w\` is not a git repository**; it holds independent repos: `w/www` (the Brain, Laravel 12, PHP 8.4), `w/members`, `w/auth` (**Checkpoint**, the identity layer; its `checkpoint_*` tables live in the *shared* database), `w/market`, `w/coordinator`, `w/communications` (Node), `w/storage_device`, `w/notes`, `w/flow`, `w/dream_graph`, `w/pdf_editor`, `w/docs` (VitePress/pnpm), `w/desktop_updates` (Laravel, no database, no Vite). `packages/afsserver` is its own repo (plans, bin scripts, nginx generation). `lab2` (`C:\projects\vagrant\lab2`) is the Vagrant lab. `packages/wwwafsportal_v1` is **out of scope**: never read or search it.
- **Plans:** `packages/afsserver/plans`. Read `plans/DEVELOPMENT_MODE.md` first (one word: `local`, `vagrant` or `remote`; it was `vagrant` this session — the lab is reachable, verify rather than infer). `w/AGENTS.md` is the instruction set for the workspace (git rules, environments, schema ownership); `w/CLAUDE.md` points to it.
- **This PC:** Windows 10, Git Bash + PowerShell. PHP CLI **8.4.25** (winget), Composer as `php composer.phar` in `w/www`. **Not installed:** `expect`, `jq`, `dig`, `nginx`. A `python3` shim exists at `C:\Users\mrmit\bin\python3` (the Windows Store stub does not run); use `python`. Portable **MariaDB 11.4.5** at `C:\Users\mrmit\mariadb` for tests (§3.2). The Windows OpenSSH **ssh-agent service** is running and holds the dev-server key (§6.3).
- **Time zone/clock:** the machine is CDT; the server clock is its own. Cron entries are server time.
- **Autoload** is classmap-optimised (`composer.json`): after moving or adding classes run `php composer.phar dump-autoload -o` in `w/www`.
- **Code markers** (`plans/markers.md`): `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION` (Direct Payments code; re-tagged from `AFS_BILLING_MODIFIER_ADDITION` in 92 files), `AFS_BILLING_MODIFIER_ADDITION` and `AFS_MEMBERSHIP_MODIFIER_ADDITION` (appfactory-side code), and the others listed there. A new `AFS_NOTIFICATIONS_MODIFIER_ADDITION` is proposed (notifications plan OQ12).

---

## 3. Testing PHP and TypeScript on this computer

### 3.1 Rules that decide how

1. **Real database.** Tests run on MariaDB (port 3307), not SQLite. SQLite hides column-length and FK-timing bugs.
2. **Always pass the database settings.** `w/www/phpunit.xml` defaults to the lab server (`DB_HOST=192.168.56.11`, database `testdb_appfactorystudio`) and a shell variable that is already set wins over `<env>`. Running `vendor/bin/phpunit` bare points at the wrong database — **never do that**.
3. **One heavy process at a time**, with progress, in the background; wipe the throwaway test database between separate runs; stop MariaDB when finished.
4. **Write everything first, run the suite once at the end**; use `php -l` and cheap checks between chunks.

### 3.2 The local test database

Portable MariaDB, no Windows service, `127.0.0.1:3307`, user `root`, **no password**, database `testdb_phpunit` (created on first use). Binaries: `C:\Users\mrmit\mariadb\mariadb-11.4.5-winx64\bin\` (`mariadbd.exe`, `mariadb.exe`, `mariadb-admin.exe`).

- **Start.** The shipped `start-mariadb.cmd` uses `start /b`, and that child dies when the launching shell exits — **it did not stay up** this session. Run the server directly as a *background* Bash task:
  `C:/Users/mrmit/mariadb/mariadb-11.4.5-winx64/bin/mariadbd.exe --defaults-file=C:/Users/mrmit/mariadb/data/my.ini --port=3307 --bind-address=127.0.0.1 --console` with `run_in_background`. Wait until `mariadb.exe -uroot -h127.0.0.1 -P3307 -e "select 1"` answers (a few seconds).
- **Stop (clean).** `mariadb-admin.exe -uroot -h127.0.0.1 -P3307 shutdown`. Then confirm with `tasklist | grep -i mariadbd` that it is gone. (`TaskStop` kills only the shell wrapper, not the server.)
- **Wipe between runs.** Leftover tables from an earlier test file cause `table … already exists` errors that look like code bugs. Clear them:
  `cd w/www && DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testdb_phpunit DB_USERNAME=root DB_PASSWORD= APP_ENV=testing php artisan db:wipe --force`.

### 3.3 Running PHPUnit

```bash
cd /c/projects/vagrant/afs-eco/w/www
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testdb_phpunit DB_USERNAME=root DB_PASSWORD= \
       AFS_TEST_WIPE=1 EXTERNALS_CORS_MODE=off APP_ENV=testing
nohup vendor/bin/phpunit tests/Feature/DirectPayments > /tmp/run.log 2>&1 &      # background, WITH progress
sed 's/\x1b\[[0-9;]*m//g' /tmp/run.log | tail -20                                # read it without the colour codes
```

- `AFS_TEST_WIPE=1` lets the test base classes rebuild tables; `EXTERNALS_CORS_MODE=off` is needed or the externals suite gets 401/403.
- Use `--testdox` for a named list, plain progress for dots with `N / total (x%)`. **Do not use `--no-progress`**: a 49-minute run then shows only a header and "what's the progress?" cannot be answered (the owner interrupted such a run).
- **Each test rebuilds about 100 tables**, which is why the suite is slow: the seven main folders (`DirectPayments`, `Processor`, `SiteUsers`, `Connections`, `Ecommerce`, `Events`, `Membership`) are **764 tests and took 49 minutes**.
- **The `tests/Feature/Brain` folder must be run file by file** with `php -d max_execution_time=0 vendor/bin/phpunit tests/Feature/Brain/<File>Test.php` (the whole folder exceeds PHP's 600 s limit). A loop that prints one result line per file works (39 files this session). Do not run two PHPUnit processes at once on the same database.
- Affected-files runs: when one area changed, run only its folder or file(s); the full suite once at the end.

### 3.4 Results at the end of this session (2026-10-19)

| Run | Result |
|---|---|
| Seven folders (764 tests) after the `app()` refactor | 762 pass; the 2 that failed were caused by the refactor (a test calling the job's `handle()` with no arguments, and a controller keeping the first-resolved extension point) and were fixed; re-run green: `ExtensionPointsTest` 18/18, `DirectPaymentMailTest` 10/10 |
| `Forms`, `Appointments`, `Builder`, `Members`, `Admin`, `Observers`, `Externals`, `Unit` (434 tests) | all pass except `LaravelCronScriptTest` (18 errors, see 3.5) |
| `Feature/Brain` (39 files, one by one) | all pass except `BrainDeviceTokenCommandTest` (4 errors, 1 failure, see 3.5) |
| Earlier whole-suite run (2026-10-17), Direct Payments audit record | `Feature/DirectPayments` 456/456 (2,440 assertions); `npm run test:external-api` 17/17; `tsc --noEmit` clean for the app and the SDK |

### 3.5 Known failures that are not code bugs

- **`tests/Unit/Scripts/LaravelCronScriptTest`** — 18 errors, `Typed property … $dir must not be accessed before initialization`: its `setUp` cannot create its temp directory under Git Bash on Windows. It tests `laravel-cron.sh`; the script itself was also checked by hand. Unrelated to PHP code.
- **`tests/Feature/Brain/BrainDeviceTokenCommandTest`** — the printed token line is not captured on Windows (`Undefined array key 1`).
- **"table already exists"** — leftover state (see 3.2), not code.
- **Pre-existing, from earlier sessions:** storage_device `OpsTest::test_untrash_moves_a_trashed_item_back_and_is_idempotent`; `BrainStorageStaffTest::test_there_is_no_route_that_deletes_a_server_or_a_device` (the global SDK "Origin required" check answers 403 in that test).
- **Do not rewrite production code to make a test pass on a weaker database.** If a path is MySQL-only, say the coverage gap plainly.

### 3.6 Cheap checks between chunks

- `php -l <file>` for every changed PHP file.
- **Container build check** (catches constructor cycles and missing bindings after an injection change): a small script boots the app and calls `$app->make(Class::class)` for every changed class; this session's lived in the scratchpad (`smoke.php`). It needs no database.
- `php artisan route:list` before/after a refactor (the namespace refactor compared 730 routes entry by entry).
- Grep gates (Direct Payments plan 11 §11.5; the notifications plan 09 §9.4).

### 3.7 TypeScript

- **The repo's own tests use Node's built-in runner**, no packages to install — from `w/www`: `npm run test:external-api` (the SDK request layer; 17 tests), `npm run test:external-ui`, `npm run test:builder`. They run `.ts` directly (`--experimental-strip-types`) through `resources/js/portal/builder/tests/register.mjs`. New logic that must be tested should be a **pure function** so these runners can reach it without a DOM.
- **Type-check `w/www`:** `npm run build` runs `check:modules` then `tsc --noEmit && vite build`. For a type check alone, `npx tsc --noEmit` in `w/www`. `npm run build` is the asset command you may run; **never run `npm run dev`/`vite`** (it writes `public/hot` and breaks every page when it dies; delete a stale `public/hot`).
- **Type-check `w/members`:** `tsc -p tsconfig.json` stops at TS5103 (`ignoreDeprecations: "6.0"` against installed TypeScript 5.9.3). Write a throw-away `tsconfig.check.tmp.json` in the members folder that `extends ./tsconfig.json` with `"ignoreDeprecations": "5.0"`, run `tsc -p` on it, delete it. About 94 lines of errors in untouched files are pre-existing; compare only the files you edited. `members` unit tests: `npm test` (vitest).
- **TypeScript style (owner's rule):** no optional chaining (`?.`) and no `any` — assert dependencies once and throw. The SDK types are in `w/www/resources/js/external/portal.d.ts`, copied (not linked) into seven consuming apps at `<app>/resources/js/checkpointHelper/static/portal.d.ts`; **update every copy in the same pass** when the externals API changes. Never import the externals source from a consuming app.
- **Build only when needed**, one build at a time: `npm run build` (www), `npm run build-afs-all` (SDK bundles). `VITE_*` values are baked in at build time — changing a `.env` does nothing to an already-built bundle until the app is rebuilt.

### 3.8 Verifying without breaking rules

- No real browser login by the agent. For an authenticated screen, ask the owner to log in; otherwise verify the same code path with a server-side script, `artisan`, or a public endpoint, and say what was not checked.
- Do not start Vite, Electron or long app runs casually; kill leftovers (`tasklist`, `wmic process where …`). Say in the final message what you checked in the process table.

### 3.9 Git Bash / Windows traps (all hit this session)

- **Heredocs eat backslashes** and fail on apostrophes. Write scripts with the **Write** tool, run them with Bash, then grep the result to confirm the backslashes survived. PHP namespaces in `sed` lose backslashes — use Edit.
- Python `open()` without an encoding corrupts UTF-8; read/write with `encoding='utf-8', newline=''` and keep each file's line endings (many files are CRLF — a script must detect and preserve them).
- `/tmp` in Git Bash is not `C:\tmp` for Python; use a real path (the scratchpad) for files both tools touch.
- `escapeshellarg()` on Windows mangles `%` and quotes; send multi-line scripts to a VM on **stdin**, not as a quoted argument. A very long command line (about 32 KB) fails on Windows (`Argument list too long`) — compress first (`gzip | base64`).
- `taskkill` of php processes needs the owner's say-so; `TaskStop` stops only the shell wrapper, so check for the real child (`php.exe`, `mariadbd.exe`, `ssh-agent.exe`).
- A `| grep -q` after a command that keeps writing can hang in Git Bash; capture output to a variable or file first.
- `tar` needs `--force-local` for `C:` paths; `jq` is not installed.

---

## 4. Working with the databases

### 4.1 Which database is which (dev server and local)

| Database | Used by | Holds |
|---|---|---|
| `testdb_appfactorystudio` | `www` (Brain), `members`, `auth` | the shared schema: Brain's control plane, members' tables, **and** Checkpoint's `checkpoint_*` tables |
| `testdb_afs_idp` | `auth` only | auth's own Laravel tables and the IdP admin panel's staff accounts |
| `testdb_afs_apps` | `coordinator`, `flow`, `dream_graph`, `notes`, `pdf_editor` | the five apps, `{app}_` table prefixes |
| `testdb_afs_market` | `market` | the marketplace |
| SQLite file | `storage_device` | it has no `DB_DATABASE`; its `db:wipe` only clears its own file |
| `testdb_phpunit` (local only) | tests | throwaway |
| one per tenant | each member website | that website's own content (a tenant is a clone of `www`) |

Names are the dev-mode values read from `_env/dev/.env`; `create-databases.php` creates whatever each app's `_env/<mode>/.env` names. Prefix meaning: `brain_*` Brain control plane, `members_*` members only, `laravel_*` the tables a clone inherits and uses, `checkpoint_*`/`ext_*` Checkpoint.

### 4.2 Schema ownership (the rule that bites)

- **`www` owns every migration for the shared database; `members` ships none.** `members` sets `DB::prohibitDestructiveCommands(true)`, so **`db:wipe`/`migrate:fresh` refuse to run there** (this stopped the first two dev-server install attempts). Wipe and migrate from `www`.
- **`auth` owns the Checkpoint schema** (`auth/database/migrations/*_create_checkpoint_tables.php`, on the `checkpoint` connection → the shared database). A clean database needs `php artisan migrate` in `www` **and** in `auth`.
- **The create-migration shortcut:** while nothing was deployed, schema changes went straight into the existing create migrations (e.g. `2026_04_03_160000_create_laravel_direct_payment_tables.php`). `migrate` does **not** re-apply an edited create migration to an existing database — such a change reaches a server only by a **fresh rebuild** (`--install-ecosystem --destroy-db`). Once tenants hold real data, switch to new migrations with a copy step (the notifications plan, OQ3).
- Guard every create with `Schema::hasTable`, name long indexes explicitly (≤ 64 chars), never reuse a migration filename across apps. `tests/Unit/Database/MigrationOwnershipTest.php` enforces this by parsing the files (no database needed).
- **Never hand-write SQL to change schema or data in place of a migration.** `migrate:fresh` on the shared database wipes everything — only with the owner's explicit authorisation each time.

### 4.3 The clean-install data: `core/config/testdb_appfactorystudio.data-only.sql`

`w/www/core/config/testdb_appfactorystudio.data-only.sql` is what `build_sites2.sh` imports after the migrations (`mysql --force`, **one pass**). It fills the Brain's own site rows (`subdomain_properties` / `website_reference_num` for `www`/`www15`), mail handlers, and the **Direct Payments and processors**. What was done this session:

- The imported Direct Payments rows were old-shaped, so a **marked block was appended** (every statement only fills an empty value): tiers get their canonical price from their active plan; tier bindings get `processor_plan_id`; Direct Payments and processors get `currency = usd`; each existing subscription gets a leg and its contract's `current_leg_id`/status/price snapshot.
- Verified against a freshly rebuilt local database: **0 refused statements** with `www` + `auth` migrations applied first. **Do not import the same file twice into one database** — the second pass produces false duplicate-key errors (this happened once and was redone).
- The lab copy `lab2/databases/production/testdb_appfactorystudio.data-only.sql` differs and was **not** touched.
- Content worth knowing (observed on the dev server after the 2026-10-19 install): 4 Direct Payments (`demo-direct-payment`, `demo-events-dp`, `vfPSdk2xj` "MyPayments" — the subscription one — and a fourth), 4 processors (`2uDJGCr` "MainStripe" sandbox, "MyPayPal" `yFr5Axf`, "MyPayPalSubscription" `EMjj0VK`, and `demoprc` used by the two demo Direct Payments), 4 tiers + 4 tier bindings, 1 subscription with 1 leg, 8 catalogue items, 2 processor bindings, **0 notification rows**. Mail handlers are attached by `item_reference_num`: `vfPSdk2xj` ("Response"), `Q2qCGSFfz` and `OEv8nCkp8` ("main"), plus forms/dashboard ones.

### 4.4 The helper scripts that prepare databases and `.env` files

- `create-databases.php --mode=<dev|prod|local…> --root <dir>` — reads `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` from every project's `_env/<mode>/.env` and makes the server match (database, user, grants). **It never touches `root`.** The admin login comes from `DBADMIN_USER`/`DBADMIN_PASS`/`DBADMIN_HOST`/`DBADMIN_PORT`.
- `set-surface-keys.sh --root <dir> --only <mode> --sync-apps [--force]` — gives every Brain env file its seven Brain surface keys and the four afs-connect service keys (distinct per app, pairs kept equal). `--force` makes new ones; without it only empty ones are filled.
- `use-env.sh <mode> --root <dir>` — copies `_env/<mode>/.env` into every project's root `.env`, all-or-nothing, with timestamped backups in `<root>/.env-backups/`. **The owner switches `.env` by hand; the agent never runs this on its own** (build_sites2.sh runs it inside a fresh install because the owner asked for that install).
- `check-env/check-env.py` (see `w/ENVIRONMENTS.md`) verifies the environment model.

### 4.5 Destroying tables safely (`--destroy-db`)

`build_sites2.sh --install-ecosystem --destroy-db` runs, **after cloning and `use-env.sh`**, in each Laravel app that exists: `composer install` if `vendor/` is missing (artisan needs it), then `php artisan db:wipe --force --no-interaction` — **once per distinct database**. Apps that share a database skip the second wipe, and `members` (which refuses the command) shares `www`'s database. It asks you to type `destroy db` (`destroy prod db` for prod, always, even with `--yes`). Only valid with `--install-ecosystem`.

### 4.6 Verification scripts

- `verify-migrations.sh` — uploads `www/database/migrations`, migrates into a scratch database `migration_scratch_after` on the dev server (≈ 3 minutes, in the background), dumps it and diffs against a baseline taken from the original 96 migrations. `schema_diff.py before.sql after.sql` compares two `mysqldump --no-data` files ignoring column and key order.
- `verify-import.sh [file.sql]` — fresh migrate + import of the data-only SQL into `migration_scratch_import`, then row counts per table against the file and a check that the app reads the imported site rows. **Only `migration_scratch_*` databases are touched** (the only ones the server user may create/drop).

### 4.7 Server database access without leaking the password

On the dev server the MySQL root password is in root's `~/.bashrc` as `DBADMIN_PASS=…` — **set but not exported**. A script started as a child of that shell does not inherit it (the first install attempt failed with "The MySQL root login does not work" for exactly this reason). `export DBADMIN_PASS` before running, or read it inside the remote command: `eval "$(grep '^DBADMIN_PASS=' /root/.bashrc)"; export MYSQL_PWD="$DBADMIN_PASS"`. Never print it; never put it on a command line or in a document.

### 4.8 Keeping the databases small (planned, not built)

Nothing prunes a tenant site today. `plans/www/notifications_and_analytics/08-data-lifecycle.md` plans one `data:prune` job (ships disabled; circuit breaker; supervised first run), raw analytics events deleted at 90 days **only after** their day is rolled up and their calendar-quarter report exists, the send ledger at 90/180 days, the inbox at 180/365, webhook payloads dropped at 90 days (that table is read back by a Square order lookup), expired sessions and tokens, plus a weekly size report and a test that fails when a new log-style table has no retention rule. The owner confirmed the retention numbers (OQ18) and "ship disabled, supervised first run" (OQ21) on 2026-10-19.

---

## 5. `packages/afsserver/bin` — what each file does

All paths relative to `C:\projects\vagrant\afs-eco\packages\afsserver\bin`. On the dev server these scripts live in `/opt/afsserver/bin` (the VS Code SFTP config for `packages/afsserver` uploads to `/opt/afsserver`).

| File | What it does |
|---|---|
| **`build_sites2.sh`** | The fresh-install and update script for the whole estate on a server (details §5.1). Replaces `build_sites.sh` |
| `build_sites.sh` | The **old** script. Superseded. It contains credentials in plain text — do not use it and never copy from it |
| **`remote-run.sh`** | Run one command on the dev server over SSH and print the output (§6) |
| `_ssh_askpass.sh`, `_ssh_pw.exp`, `_ssh_run.exp` | Helpers for `remote-run.sh`: the askpass fallback when `expect` is absent (Windows), and the `expect` drivers (macOS/Linux). `_ssh_askpass.sh` must keep its execute bit (`git update-index --chmod=+x`) |
| **`upload-changed.sh`** | Upload the named files over SFTP using `<project>/.vscode/sftp.json` (`--vscode`); backs up each remote file first into `w/.upload-backups/<timestamp>/`; `--dry-run` shows the plan; refuses to send a `.env` over plain FTP; `upload-files.txt` is a sample list |
| `create-databases.php`, `set-surface-keys.sh`, `use-env.sh` / `use-env.ps1` | Prepare databases, keys and `.env` files (§4.4) |
| **`permissions.sh`** | `sh permissions.sh build <site folder> <system user>`: `chmod -R 755 /home/<user>` and `chown -R apache:apache /home/<user>` (**the whole home**), then makes the site's `core/scripts/*.sh` executable, compiles `wrapper.c` into `core/scripts/bin/afs-website` and sets it root-owned with the setuid bit. **`afs-website` is the binary every shell command of the ecosystem runs through.** It resets ownership of everything under `/home/<user>` (§5.2) |
| `pull-install-apps.sh` | Production (CentOS Stream 9): clone/pull the apps from GitHub, `composer install --no-dev`, `npm ci`; needs a GitHub token and a private-npm-registry token supplied by the operator; `--apps`, `--no-composer`, `--no-npm`, `--build` |
| `verify-migrations.sh`, `verify-import.sh`, `schema_diff.py` | Migration and import verification on scratch databases (§4.6) |
| `golive.sh` | Older go-live helper (its header lists `members,auth,pm,graph,notes,pdf,flow,market`); not used this session |
| `copy_portal_source.{sh,bat,ps1}` | Copy the portal source (older tooling; not used this session) |
| `afs-build` | A compiled binary (not text); do not edit |
| `check-env`, `node` | Empty placeholders |

### 5.1 `build_sites2.sh` in detail

**Usage.** With **no options it prints the help and exits 0** (nothing is installed). A mode is required: `--install-ecosystem` (a fresh install) or `--update-repos`. `--destroy-db` is valid only with `--install-ecosystem`. Options alone (`--dry-run`, `--only`) exit 2 with "Nothing to do". `bash build_sites2.sh --help [topic]` — topics: `desktop_updates`, `scheduler`, `update-repos`, `prod`, `afs-website`, `logs`, `topics`.

```bash
bash build_sites2.sh --install-ecosystem                      # asks for the MySQL root password (or reads DBADMIN_PASS), asks before wiping
bash build_sites2.sh --install-ecosystem --destroy-db --yes   # the run done on dev: also drops every app's tables first
bash build_sites2.sh --update-repos [--only www,members]       # stash local changes, git pull, composer, migrate, npm build, docs, permissions, scheduler
bash build_sites2.sh --install-ecosystem --deploy-type prod    # /home/appfactorystudio/websites/prod; asks for "wipe prod"; skips the data import
```

| Option | Meaning |
|---|---|
| `--install-ecosystem` / `--update-repos` | the two modes (mutually exclusive) |
| `--destroy-db` | with install: `db:wipe` each distinct database after clone + `use-env.sh` (§4.5) |
| `--deploy-type dev\|prod` | root folder `/home/appfactorystudio/websites/dev` or `…/prod`; selects the env profile (`use-env.sh dev\|prod`), `create-databases.php --mode=`, the surface-key mode; **prod always asks for the words "wipe prod"**, never loads the test data unless `--import`, and uses its own releases folder `desktop_updates_prod` |
| `--only a,b,c` | restrict to these apps |
| `--no-wipe`, `--keep-keys`, `--yes/-y`, `--dry-run` | keep the folder; do not regenerate surface keys; skip the wipe question (dev only); print the steps and run nothing |
| `--no-db-import` (= `--no-import`), `--import` | do not / do load `testdb_appfactorystudio.data-only.sql` |
| `-h`, `--help [topic]` | help |

Environment variables (defaults in brackets): `DEPLOY_TYPE` [dev], `DEV_ROOT`, `DB_NAME`, `SQL_IMPORT`, `APP_USER`/`APP_GROUP` [apache], `NODE1_DIR` [/mnt/node1/appfactorystudio], `GITHUB_USER`/`GITHUB_TOKEN`/`GITHUB_ORG`, `DBADMIN_PASS`, `RELEASES_DIR`, `PUBLISH_USER` [appfactorystudio], `DEPLOY_OWNER` [root], `PERMISSIONS_DIR` [/opt/afsserver/bin], `SYSTEM_USER`, `LOG` [/tmp/build_sites2.<time>.log].

**Order of a fresh install:** 0 tool and root-login checks → 1 wipe (asks) and create the folder → 2 clone every repo (token not left in the remote URL) → 3 create databases, set surface keys, switch the env files (`use-env.sh`), optional `--destroy-db` → 4 per app: `composer install`, `migrate`, seed (only if the app has a `DatabaseSeeder`), `npm ci`/`install` (only if it has a `package.json`), `npm run build` (`build-all` on `www`); Node service `communications` (npm ci + tsc); `desktop_updates` (PHP only: `composer install --no-dev`); `docs` (a script) → 5 import the data-only SQL → 6 folders under `/mnt/node1/...` and **ownership** → 7 **afs-website** (`chmod +x permissions.sh`, then `permissions.sh build` for `www` and `members`) → **`desktop_updates` ownership and releases folder (after step 7, see 5.2)** → 8 checks (nothing pending in any database, front-end builds present, `VITE_SUBDOMAIN` set) → 9 **the scheduler, always the last action:** `laravel-cron.sh --install` for `www` and `members`. The script stops at the first step nothing after it can work without (clone of `www`/`members`, databases, `www` migrate); other failures are recorded, the rest still builds, and the summary at the end lists every failed step and the exit code is not 0.

**`--update-repos`:** no wipe, no new databases, no new keys, no env switch, no import, no seeding; per repo `git stash push --include-untracked` of local changes (kept, recoverable), `pull --ff-only`, `composer install`, `npm ci` **only if** a package file changed or `node_modules` is missing, `npm run build`, migrations, docs, permissions again, and the scheduler restarted (`--restart`). Edited *create* migrations are **not** re-applied this way (§4.2).

**`desktop_updates`:** the update server for the desktop app. Code owned `root:apache`, no access for others (`750`), `storage` and `bootstrap/cache` apache-writable; releases live **outside every web folder** on the removable device `/mnt/node1/appfactorystudio/desktop_updates`, owned `appfactorystudio:apache` with directories `2750` (setgid) and files `640`, so the web user reads and serves but cannot write a release. The publishing account is added to the `apache` group (`usermod -aG`) so it can run `php artisan releases:publish` (`sudo -u appfactorystudio sh -c 'umask 027 && cd <dev>/desktop_updates && php artisan releases:publish <tree> [--channel=stable] [--dry-run]'`). `UPDATES_ROOT` is written into its `.env`. `bash build_sites2.sh --help desktop_updates` lists publish, dry-run, resign, rollback, list, prune and the `curl` checks.

### 5.2 Lessons from running it on the dev server (2026-10-19, four attempts)

1. **`DBADMIN_PASS` was not exported** (§4.7) → "MySQL root login does not work" at the checks step; nothing changed.
2. **`db:wipe` needs `vendor/`** — it ran right after clone, before any `composer install` → exit 255. Fixed: install first if `vendor/` is missing (this is why the first pass changed the dev folder but dropped no tables).
3. **`members` refuses `db:wipe`** (`prohibitDestructiveCommands`) and shares `www`'s database → fixed: wipe once per distinct database.
4. **`storage_device`** has no `DatabaseSeeder` and no `package.json` (an API with a SQLite file) → seed and npm steps skipped for any app without them.
5. **`permissions.sh build` resets `/home/appfactorystudio` to `apache:apache 755`**, which undid the `desktop_updates` `root:apache 750` ownership set earlier. Fixed by moving the `desktop_updates` ownership step **after** `permissions.sh`. The live folder was repaired by hand with the same operations.
6. The fixed script was **uploaded to `/opt/afsserver/bin/build_sites2.sh`** four times during the session (gzip + base64 over `remote-run.sh`; one earlier attempt failed because the command line was too long). The third complete run is the one whose output is in §6.7; it was **not** re-run after the `storage_device` and ownership fixes. The repository commit is `4ab8bdfe` (afsserver).
7. A run takes a long time (clones + composer + npm builds for ~13 apps); run it **detached** on the server and wait for the process to end (§6.4).

### 5.3 `w/www/core/scripts/laravel-cron.sh`

Installs the Laravel scheduler line (`* * * * * { cd <app>/ && /usr/bin/php artisan schedule:run; } >> /dev/null 2>&1`) in the **apache** crontab, one comment + one line per app, a shared `SHELL=/bin/sh` line (apache's login shell is `/sbin/nologin`). Modes: `--check`, `--install`, `--remove`, `--list`, `--stop` (pauses an entry by prefixing `#STOPPED# `), `--start`, `--remove-all`; options `--user`, `--group`, `--path`, `--verify`, `--restart` (restart crond). **It never adds an entry twice** — it repairs duplicates down to one and switches a paused entry back on instead of adding another — and edits the shared crontab under a lock. Tests: `tests/Unit/Scripts/LaravelCronScriptTest.php` (they error on Windows, §3.5). The scheduled commands a site runs are in `w/www/routes/console.php` (queue worker every minute, charge-hook repair, subscription notify/migrate, scheduled changes, ecommerce holds); the Brain-only commands are scheduled in `members` and triggered on the Brain through `POST /api/internal/brain/commands/{name}`.

---

## 6. Working with the remote dev server

### 6.1 What it is

`45.79.42.137`, running the dev copy of the estate under `/home/appfactorystudio/websites/dev/<app>`: crond, a MySQL server (client 8.0.41), PHP 8.4 (8.4.18 when last read), the `apache` web user, root login by SSH. The public site for the Brain is `https://www15.appfactory.studio` (the owner registered the **Stripe webhook** `https://www15.appfactory.studio/portal/webhooks?type=stripe&p=2uDJGCr`; `p=` is the processor reference `2uDJGCr`, "MainStripe"). The server's SFTP/SSH details are in `w/www/.vscode/sftp.json` and `packages/afsserver/.vscode/sftp.json` (host, port, user, password or key path — **read them from there, never copy them out**).

### 6.2 `remote-run.sh` — run one command, print the output

```bash
cd /c/projects/vagrant/afs-eco/packages/afsserver/bin
./remote-run.sh -r 'df -h /mnt/node1'                         # -r: start in /, real root login
./remote-run.sh -p www 'tail -n 80 storage/logs/laravel.log'   # -p: the project's folder from its sftp.json (www default, auth, members …)
./remote-run.sh -d /var/log/nginx 'tail -n 20 error.log'       # -d: an absolute folder
./remote-run.sh --dry-run 'ls'                                 # show what would run, connect to nothing
```

Options: `-p/--project`, `-d/--dir`, `-r/--root`, `-i/--key`, `--key-only` (never fall back to a password), `-t/--timeout <sec>` (default 120), `--dry-run`. It prints server, user, folder and command on stderr first, runs the command **as one string** in that folder, and exits with the command's own status. **Anything that changes the server should be agreed first.** Host key: a new server is trusted once; a changed key is refused.

### 6.3 Authentication (key with a passphrase, password fallback, macOS)

- **Key.** The key has a passphrase. Git Bash's own `ssh` cannot talk to the Windows `ssh-agent` (a named pipe), so when the Windows agent is running and `SSH_AUTH_SOCK` is unset, the script uses the **Windows** `ssh.exe` (`/c/Windows/System32/OpenSSH/ssh.exe`) and the agent supplies the unlocked key. The agent is the Windows OpenSSH service (running since 2026-10-17); load the key into it once with `ssh-add` — the owner ran the setup commands in PowerShell on 2026-10-17. The passphrase is never stored in a file.
- **Password fallback** (key refused and `sftp.json` has a password): with `expect` present it uses `_ssh_run.exp`/`_ssh_pw.exp`; **without `expect` (Windows)** it uses `_ssh_askpass.sh` as `SSH_ASKPASS` with `SSH_ASKPASS_REQUIRE=force` and `PubkeyAuthentication=no`, taking the password from the environment (`AFS_SSH_PASS`), never from a command line or a file. `--key-only` forbids it.
- **macOS:** the same script works (it has `expect`; the Windows-agent branch is skipped).
- **It is slow.** Each connection took roughly 60–90 seconds on this machine, so calls routinely exceed a 60–90 s tool timeout and run in the background. Batch several checks into **one** call.

### 6.4 Running something long on the server

Detach it and wait; do not hold an SSH session open for an hour:

```bash
./remote-run.sh -r -t 3500 'cd /opt/afsserver/bin && export DBADMIN_PASS && LOG=/tmp/build_install.log \
   nohup bash build_sites2.sh --install-ecosystem --destroy-db --yes > /tmp/build_install.out 2>&1 < /dev/null & \
   sleep 5; for i in $(seq 1 1700); do pgrep -f "build_sites2.sh --install-ecosystem" >/dev/null || break; sleep 2; done; tail -200 /tmp/build_install.out'
```

Output goes to `/tmp/build_install.out`; the step log to `/tmp/build_install.log` (`/tmp/build_sites2.<time>.log` by default). Start the local call with `run_in_background` and read the result file when it finishes.

### 6.5 Getting files onto the server

- **A script or a few files:** compress and send in the command (works up to what fits a Windows command line, ≈ 32 KB — gzip first): `B64=$(tr -d '\r' < build_sites2.sh | gzip -9 | base64 -w0)` then `./remote-run.sh -r "echo '$B64' | base64 -d | gunzip > /opt/afsserver/bin/build_sites2.sh.new && bash -n /opt/afsserver/bin/build_sites2.sh.new && mv … && chmod +x …"`. Convert CRLF to LF first.
- **Application code:** `upload-changed.sh --vscode -y -f upload-files.txt` (SFTP, with automatic backups), or the VS Code SFTP extension (`uploadOnSave`) — **the owner uploads code; the agent does not edit application code on the server.** After code is on the server, a new create-migration is not applied by `migrate` (§4.2).
- A "remote agent" (another assistant on the server) may read, run read-only checks and edit server **configuration** only, never application code; hand it work through `packages/afsserver/tmp/handoff-share-remote.md`.

### 6.6 Rules for the server

1. **Do not run anything unless the owner says so; say each command first** (and what it changes). The owner said "tell me when you want to run commands so that I know what you're doing".
2. Read-only checks first. Write down every command you ran and every change you made (§6.8).
3. Never print or store a password, token or key. Passwords reach scripts through the environment.
4. Do not install packages on the server without asking.

### 6.7 What is on it (verified 2026-10-19, read-only checks plus the install run)

- `/opt/afsserver/bin/build_sites2.sh` is the current version (has `--install-ecosystem`); `permissions.sh` present; `/mnt/node1` is a mounted device (49 GB, ~81 % used).
- The dev folder was wiped and re-cloned (13 repos), `.env` files switched to dev, surface keys regenerated (`.env-backups/<time>-dev/`), `VITE_SUBDOMAIN=www15`.
- Databases wiped once each (`testdb_appfactorystudio`, `testdb_afs_idp`, `testdb_afs_apps`, `testdb_afs_market`; the SQLite file for `storage_device`), then migrated; "nothing pending" checks passed for every app.
- Data import ran; counts in §4.3. `laravel_direct_payment_notifications` has **0 rows**.
- **apache crontab:** exactly one scheduler line for `www` and one for `members` (`laravel-cron.sh --check` reports both present; `crond` active).
- **`desktop_updates`:** code `root:apache 750`; `storage` `apache:apache 770`; releases `/mnt/node1/appfactorystudio/desktop_updates` `appfactorystudio:apache 2750`; `appfactorystudio` is in groups `appfactorystudio, wheel, apache`; `UPDATES_ROOT` set. `/home/appfactorystudio` is `apache:apache 755` and has no `.ssh` folder.
- Not checked: the mail provider row and key (`laravel_mail_providers`), the queue worker's recent runs, `jobs`/`failed_jobs`, whether the Stripe webhook has delivered anything, the Apache/nginx virtual hosts.

### 6.8 Ledger of what was done on the server this session

Read-only: file/permission/`stat` checks, `crontab -l -u apache`, `laravel-cron.sh --check`, table counts and a few `SELECT`s as root (password read inside the command, never printed), log tails. **Changes** (all with the owner's go-ahead for that task): uploaded `build_sites2.sh` (4 times); ran the fresh install three times (the first two stopped early); one manual ownership repair on `/home/appfactorystudio/websites/dev/desktop_updates` (`chown -R root:apache`, `chmod -R u=rwX,g=rX,o=`, and apache ownership of `storage` and `bootstrap/cache`). Nothing else.

### 6.9 Other places that exist on the server side

`lab2` (`dns` 10.0.0.242, `brain` 10.0.0.243, `web` 10.0.0.244) is the Vagrant lab, reachable from this PC with a documented read-only key (`lab2/REMOTE-ACCESS.md`); try before claiming it is unreachable. Production uses `pull-install-apps.sh` and `build_sites2.sh --deploy-type prod`; production-hardening notes are in `lab2/security.md`.

---

## 7. Member creation, payments and e-mails

### 7.1 The words

- **Direct Payments** is the platform's own payment API (`w/www/app/Externals/DirectPayments`, SDK in `w/www/resources/js/external/api/direct_payments`). It knows **payers, subscribers, subscriptions, charges, renewals** and nothing about members, billing or tiers of the studio. Say **"the provider"**, not a provider's name, when talking about the charging side.
- A **member** and a **membership** (`brain_memberships`, tiers, levels) are **appfactory studio** concepts. They use Direct Payments the way any tenant website does, through extension points ([`plans/www/direct_payments_audit/09-extension-points.md`](www/direct_payments_audit/09-extension-points.md)). The appfactory-side code is switched on by `billing.direct_payments_host` and registered in `app/Providers/AppfactoryDirectPaymentsServiceProvider.php`.
- **Every charge goes through an invoice of Direct Payment catalogue items.** The **browser** builds the invoice with the SDK (`createInvoice → addItem → lock → setupSinglePayPayment → confirmPayment`), and the server only verifies with the provider (`PaymentGateway::isPaid()/snapshot()`). Read the playground samples (`w/www/public/playground/externals/direct-payments/`) before touching anything that charges money.

### 7.2 Creating a member (signup) — the code, in order

| Where | What happens |
|---|---|
| **Browser (TypeScript)** — `w/www/resources/js/afs/create.ts`, `resources/js/afs/memberships/index.ts` | The signup page. For a paid tier it builds the invoice with the SDK, starts the subscription (`startSubscription` — returns a `subscription_reference` `sub_…` since this session) and posts to the register endpoint. The page and SDK **still store and send the provider's subscription id** as `membership_id`; the server resolves it to the same subscription, so it works, but the plan's intended switch to the `sub_…` reference is **not done** |
| **Route** — `w/www/routes/portal.php:1397` | `POST /portal/api/v1/memberships/register` ("create a membership, admin + public"); listed among the public write routes in `RoutePolicy` |
| **`MembershipController::store()`** — `w/www/app/Http/Controllers/Portal/Admin/MembershipController.php:244` | Validates; **payer**: a paid signup already has one (`saveInvoice()` assigns every invoice a payer and returns it), a free signup resolves one by e-mail with `PayerService::resolveForCheckout()`; **claims the subscription** with `App\Services\Billing\SubscriptionClaim` (one conditional write: a replay is refused, a retry by the same payer is allowed, an unknown handle is refused; it accepts the `sub_…` reference or the provider id of any leg); sets the **tier** through `MembershipTierResolver` (asks each of the member's subscriptions for its *current leg*; an `ended`/`incomplete` subscription never governs); writes the member row; runs the legacy storage-provisioning step (needs a real database connection); then the **welcome-e-mail block** |
| **The welcome-e-mail block** | Left **exactly as it is** (owner's instruction). Since 2026-10-17 `store()` wraps the call to `contructMailHandler` in try/catch and logs `[Membership] welcome e-mail not sent` — before, a free signup with no `mail_handler_reference_num` threw a TypeError *after* the member row existed, so the visitor saw a 500 and a retry said the username was taken. The receipt design that replaced the welcome e-mail is §7.4 |
| **Direct Payments side** | `DirectPaymentSubscriptionController::store()` links a subscription to a Direct Payment; the **contract + legs** model (`SubscriptionContracts`, `laravel_direct_payment_subscriptions` + `…_subscription_legs`) lets a subscription move processors without a new handle; `PayerService` is the single writer of payers and provider customers |
| **members (TypeScript)** — `w/members/resources/js/controllers/BillingApp.ts` | The members dashboard's billing calls (get, history, update, cancel, set payment method) **no longer send `member_ref`**; the Connect sync reads `connect_user`. `portal.d.ts` copies were updated in `www`, `members`, `market` |

**Tests:** `MemberWithMembershipSignupTest` (paid signup by reference, by a provider id, a replayed handle lands free, a free signup — it stops at the legacy storage step); `SubscriptionClaim` tests; `TierResolverFollowsSubscriptionTest`; `MembershipSignupTierClaimTest` (older; replicates the claim block rather than calling the controller). **`MembershipController::store()` was never run end to end** (storage provisioning needs a real connection), and the signup page was never run in a browser.

### 7.3 A payment — the code, in order (after the `app()` refactor)

1. The provider calls the webhook: `POST /portal/webhooks?type=stripe&p=<processor ref>` → `ProcessorWebhookController` (`app/Http/Controllers/Externals/Processor/`). The signature is checked against the processor's secret; the raw event is stored in `laravel_processor_webhooks` (full JSON payload); unmatched or rejected calls go to `…_webhook_orphans` / `…_webhook_rejections`.
2. `invoice.payment_succeeded` (Stripe): the invoice row is matched (by invoice reference in metadata, then by payment-intent/subscription, then — for non-renewals — by the customer's pending invoice, else a new one is created), saved `succeeded` with amount/currency/charge id, linked to its subscription (`SubscriptionInvoiceLinker`), `markChargeHookPending()`; then, for a subscription payment, `$this->notificationDispatcher->dispatchEvent('payment_succeeded', …)` (the optional notification path, §7.4).
3. `charge.succeeded` (Stripe; the only event that carries card brand/last4) → `ChargeSucceeded::handle($invoice)` (`app/Externals/DirectPayments/ChargeSucceeded.php`). Runs for **every provider and charge type**, once per charge (a cache lock plus `metadata.mail_queued_at` on the invoice), and queues `SendDirectPaymentMail` with a delay (`config('direct_payments.mail_delay_seconds', 30)`). `direct_payments:repair-charge-hooks` (every 5 minutes) re-runs the hook for charges that missed it.
4. The queue worker runs the job (§7.4). PayPal and Square reach `ChargeSucceeded` through their own handlers (`PAYMENT.SALE.COMPLETED`, `payment.updated`, `invoice.payment_made`…) — the gaps closed in `create_member_redo/complete/12-followup-batch`.

### 7.4 The e-mail — who gets what (read the code in this order)

| Step | File | What it decides |
|---|---|---|
| Job | `app/Jobs/SendDirectPaymentMail.php` → `handle(DirectPaymentCommunicationService $communication)` | loads the invoice, calls `paymentSucceeded($invoice)` |
| **Which e-mail** | `app/Mail/Externals/DirectPaymentCommunicationService.php` | the recipient is the `email` variable (empty or `guest@example.com` = nothing sent). **If the Direct Payment has a `payment_receipt` notification setting:** send only that (its mail handler, or the built-in default if none), and only if active. **Otherwise:** send every mail handler attached to the Direct Payment (`laravel_mail_handlers.item_reference_num = <Direct Payment ref>`), **except** any a notification setting uses. **If neither exists, nothing is sent** |
| Handler or default | `app/Externals/DirectPayments/Mail/DirectPaymentMailer.php` | a handler reference → that handler; no reference → the built-in default for the event (`DirectPaymentDefaultEmail`, 14 MJML templates + `defaults.json` in `w/www/resources/js/portal/admin/afsemail/mjml-templates/mjml/defaults/dp/`) |
| The one send seam | `app/Mail/MailHandlerSender.php` (via `MailTransport`/`MailHandlerTransport`) | picks the active mail provider (`laravel_mail_providers.status = 1`; key from the row or the Brain's e-mail-provider token — none = `no_provider_token`), renders the handler's MJML with the per-recipient variables, sends one recipient per call |
| Variables | `app/Mail/Externals/ClientVariables.php`, `config/mail_variables.php`, `app/Mail/variables.json`, `app/Mail/modules/*` | the module's own variables, then the providers listed in `config/mail_variables.php` (`direct_payments` module); `email` is the only variable a provider can change |
| **Second path (optional)** | `app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` | the *per-event, per-Direct-Payment notification settings* (`laravel_direct_payment_notifications`: event, mail handler, active, recipient mode, days before…). Used for scheduled reminders (`upcoming_charge`, `trial_ending`, via `direct_payments:subscription-notify` every 15 minutes) and webhook events (`payment_failed`, `subscription_canceled`, `payment_succeeded`…). Active switch is **per event**; the form is in the Direct Payment admin (`directpayments-form.html`, `directpayments.ts`). Ledger table prevents double sends. **A payment can therefore send two e-mails** (receipt + a `payment_succeeded` notification) if both are configured — the notifications plan proposes folding them |
| The four processor-change e-mails | `MjmlDefaultEmailSender` | sent during a processor swap/migration whatever the settings say (`DP_E_NOTIFICATION_REQUIRED` stops switching them off) |
| appfactory's own mail | `app/Mail/BillingMailer.php` | dunning ladder and the Brain's per-app mail; separate from Direct Payments |

**Facts that decide whether an e-mail goes out:** a recipient address on the invoice/payer; a handler attached to the Direct Payment *or* a `payment_receipt` setting; an **active mail provider with a key**; a **running queue worker** (the scheduler's `queue:work --stop-when-empty --max-time=55` line every minute, `QUEUE_CONNECTION=database`); and the webhook events `invoice.payment_succeeded` and `charge.succeeded` actually reaching the server. On the dev data, of the four Direct Payments I saw only **`vfPSdk2xj` ("MyPayments") has an attached handler** ("Response"); `demo-direct-payment` and `demo-events-dp` have none, so a payment on them sends no receipt until a `payment_receipt` setting or a handler is added.

### 7.5 The Direct Payments audit — what each step is (all built; records in `www/direct_payments_audit/complete/`)

Plan: [`www/direct_payments_audit/`](www/direct_payments_audit/README.md) — the promise: *a developer puts the Direct Payments API on a website once; the owner can later replace the payment provider behind it and nothing breaks.* Test totals at the end: `Feature/DirectPayments` 456/456 (2,440 assertions) on MariaDB.

| Step | Content (state) |
|---|---|
| 00 overview | the verdict and the ten problems — all fixed in code (00) |
| 01 findings | A1–A61 re-checked |
| 02 target model | processor, Direct Payment, binding, payer, catalogue, subscription contract and legs |
| 03 processor lock-in | a processor is never deleted, only archived; its account identity is locked; `ProcessorAccountLockTest` |
| 04 payer/customer | one `payer_reference_num`, many provider customer ids; `PayerService` the single writer |
| 05 provider parity | tier canonical price, plan provisioning, canonical discounts, Stripe/PayPal/Square capability matrix |
| 06 swap engine v2 | server-side routing, binding version (`DP_E_BINDING_CHANGED`), go-live, scheduled changes, revert |
| 07 subscription continuity | contract + legs, three migration strategies (copy card / re-authorise / no action), no gap and no double charge in the engine's tests |
| 08 SDK & implementer contract | SDK changes, `portal.d.ts` copies, playground |
| 09 extension points | `SubscriberIdentity`, `SubscriptionResponseDecorator`, `PayerDisplayResolver`, `AdvancedConfigAuthority`, `RetryPolicyDefaults`; Direct Payments defaults |
| 10 webhooks/history/integrity | webhooks for archived processors, provider-ended subscriptions, history |
| 11 rollout & testing | phases PA0–PA9 + DE, gates, the whole-suite run |
| 12 open questions | answered 2026-10-16 |
| 13 naming & separation | vocabulary gate (`VocabularyGateTest`); `AFS_BILLING_…` re-tagged in 92 files |
| 14 appfactory modifications | M1–M10 (subscriber identity, billing-state decorator, display resolver, level-99 rule, retry defaults, tier follows the current leg, `SubscriptionClaim`…) |
| 15 default emails | 14 built-in templates, one rule (chosen handler else default) through `MailHandlerSender`; **no real e-mail sent** |
| 16 safety rails | archive instead of delete, the `orWhere` precedence fix, swap atomicity — built first |
| extras `00_extras.md` | E1 cross-check + member-with-membership test; E2 renewal-page pre-fill; E3 test/code fixes from the whole-suite run; E4 server access tooling; E5 clean-database import + the SQL rows |

Known and recorded: **gate G2** (about 70 direct `Processor::where('reference_num', …)->first()` reads left — none uses `orWhere`; a bulk rewrite to `ProcessorLookup` was abandoned) and **G7** (readers of the contract's cached provider columns, expected until the columns are dropped); **Square wallets-first** not done.

### 7.6 `create_member_redo` plans (earlier, `plans/create_member_redo/`)

01 website reference (done) · 03 welcome e-mail (superseded by 07) · 04 charge-succeeded hook · 05 webhook registration · 06 members processor removal · 07 Direct Payment e-mail · 08 queue worker · 09 Brain schedule move · 10 run as apache · 11 domain and website on dev · `complete/12-followup-batch` (payment gaps, Name.com by environment, scheduled commands, install hardening). All coded and locally tested; **nothing run against real providers or a real mailbox.** Cleanup list still open in its overview: appfactory concepts left in Direct Payments code (mostly closed by audit steps 09/13/14), `type.txt` replaced by `VITE_SUBDOMAIN`, the `www15` folder literals.

### 7.7 Verified and not verified

| Verified (tests or reading) | Not verified |
|---|---|
| Signup claim/tier/payer paths against MariaDB with a recording transport; receipts choose the right handler/default and variables; webhook handlers for Stripe/Square/PayPal against fixtures; the swap and migration engines against faked providers; the clean-database import (0 refused statements); signup through the real HTTP kernel against the imported database; fresh install on the dev server (apps migrated, data imported, crons, ownership) | A **real provider payment** and webhook delivery; a **real e-mail** (provider, MJML transform, Gmail/Outlook rendering); the signup page, renewal page, admin screens and the bell **in a browser**; PayPal/Square sandboxes; `app_install.sh`/`commands.sh` end to end; the Vite bundles after the latest edits |

### 7.8 Open points about member creation and payments

1. Switch the signup page/SDK to send the `sub_…` reference (the server already accepts both).
2. Decide the duplicate-e-mail question and the default-when-unconfigured behaviour — notifications plan OQ7 and OQ13 (a payment with no handler/setting sends nothing today).
3. Square wallets-first and pre-fill on Square; the hosted renewal page `…/portal/dp/renew/{token}` pre-fills Stripe only.
4. Dunning/staff readers (`DunningService`, `Brain/Staff/*`, `BillingEnforceMembership`) still read Direct Payments tables directly (plan 14 §14.4).
5. G2/G7 clean-ups; the `defaults.json`/template look in real mail clients.
6. The owner's uncommitted `MembershipController` hunk (placeholders for amount/brand/last4) — find out why it is there before changing that area.

### 7.9 Proving a payment e-mail on dev (a checklist; from reading the code, not yet run)

1. The Stripe sandbox webhook is registered (`…/portal/webhooks?type=stripe&p=2uDJGCr`) with at least `invoice.payment_succeeded` and `charge.succeeded`, and the processor holds the webhook secret (`ProcessorWebhookAdminController` shows `url`, `status`, `enabled_events`, `last_error`).
2. `laravel_mail_providers` has an active row with a key (or the Brain's token is set). **Not checked on dev.**
3. The Direct Payment you pay on has an attached mail handler or a `payment_receipt` setting. (`vfPSdk2xj` has the "Response" handler.)
4. Pay with the playground/SDK flow (a sandbox card). Expect: an invoice `succeeded` with `metadata.mail_queued_at`; a delayed row in `jobs` that disappears after about a minute; the e-mail.
5. If it does not arrive, look in `www/storage/logs/laravel.log` for `[ChargeSucceeded] could not queue the mail`, `[DirectPayments] Receipt not sent` (with the error), `[DirectPayments] Mail handler send failed`; check `failed_jobs`; check `laravel_processor_webhooks` (`processed`) and `…_webhook_rejections` (a bad signature or wrong secret); run `php artisan direct_payments:repair-charge-hooks`.
6. Report what happened; do not "fix" by editing code on the server.

---

## 8. Deploying websites

Two different things are "deployment":

**(a) The ecosystem apps on a server** (`www`, `members`, `auth`, `market`, … `desktop_updates`) — `build_sites2.sh` (§5). Clone, databases, keys, env, migrations, builds, ownership, `afs-website`, scheduler.

**(b) A member's own website (a tenant)** — created from the members dashboard. **Owner decision 2026-10-09: the old way, not the new Brain agent.** The flow: members `DomainController::createWebsite` → `background_runner.php` → `core/scripts/commands.sh` → `core/scripts/remote/builder/app_install.sh`, which clones the template, writes the tenant `.env`, creates the database, the vhost and (for custom domains) the certificate. Why not the new `DeployRunner` + `afs-tenant-agent`: it never delivered `subdomain_properties` (inserted at build time from members' data; the tenant cannot work without them), it dropped the `www` / `www-own` split (each copy needs its own `.env` `APP_URL`/`ASSET_URL`), it has no certbot, and the template contract is unchecked.

What was done on the old path (members `7a4fda9f`, `dd6fbb7e`, `d78e5fae`; www plans `create_member_redo` 09–12):

- Brain routes used by the install are **signed** (they were unauthenticated and now 401); `VITE_SUBDOMAIN` is the single source of the site label (`www` unless `.env` says otherwise); folders come from `base_path`, not `www15` literals.
- **Name.com API chosen by environment** (`AppEnvironment::nameComApi()`: dev → `api.dev.name.com`, real → `api.name.com`; dev never touches the real API).
- **Install hardening:** `remote/builder/build_checks.sh` preflight (tools, disk, MySQL root, Git), every step's exit code and output checked, Apache/nginx configs tested before reload (old files restored on failure), root password in a 0600 options file (never a command line), git push credentials by environment, job files removed on exit; per-build progress token (hash stored, wrong token = 403), three retries for transient failures, a build silent for 90 minutes shows as failed.
- **The tenant's scheduler is installed as the last build step** (members `d78e5fae`) with `laravel-cron.sh`.
- Certificates: the sites name `<ref>.sites.appfactory.studio` uses a wildcard; custom-domain certbot runs only for an active, proven domain claim. A website is tied to a domain; **one active claim** per domain.
- Brain facts for a tenant come from the Brain's `subdomain_properties` (system user, domain, subdomain, `api_url`, name servers); tenant `Username` is the member reference, `Domain2` is `{sub}.sites.{BrainDomain}`.
- `domains_deploy` plan (`plans/domains_deploy`): steps 02–11 built on the Brain (claims, DNS zone engine, purchase saga, server selection, deploy pipeline, transport API, lab environment); **step 12 (move a tenant between servers) is not built**. Domains settings live **only in the Brain settings document** (`DomainSettings::get('a.b')`), never `.env`; ssh to other servers is by the password stored on each server's record, driven by `expect`. Purchases: the **browser** builds the invoice; the server stores the three references. Name.com runs on CORE v1; the platform is the registrant for now.
- **Not verified:** `app_install.sh` / `commands.sh` end to end (syntax-checked only), a real dev domain run and purchase, real Name.com dev calls, the progress tokens in a browser.

Where the code is: members `core/scripts/remote/builder/{app_install.sh,build_checks.sh,app_install_template.sh}`, `core/scripts/{commands.sh,background_runner.php}`, `app/Http/Controllers/Brain/DomainController.php` (`createWebsite()`); www `core/scripts/{commands.sh,wrapper.c,bin/afs-website,run_setup.sh}`, `app/Services/Brain/{Domains,Deploy,Ssh}/`, `app/Http/Controllers/Internal/DomainsSurfaceController.php`.

---

## 9. The notifications, analytics and data-cleanup plan (written, not built)

`plans/www/notifications_and_analytics/` — `README` + steps 00–10. The owner's brief (2026-10-19): make `NotificationDispatcher` a **general system** with two jobs — update the admin dashboard notification inbox and send the e-mail — abstracted out of Direct Payments; keep **analytics** as separate classes that work hand in hand ("a payment triggers a notification *and* an analytical record"); and keep the database small.

- **Design:** a module calls `ActivityPublisher::publish(Activity)` once; it hands the `Activity` to `NotificationDispatcher` (inbox channel + mail channel, per-event Dashboard/E-mail switches, a unique send ledger) and to `AnalyticsRecorder` (server-side events + daily rollups). Modules register their events in one catalogue; Direct Payments becomes an adapter.
- **Defects found and put in P0:** `PATCH /notifications/mark-all-read` is declared after `/{id}` (unreachable); the bell renders message text with `innerHTML` unescaped; the sponsorship producers are broken; the server never records an analytics event and nothing writes the rollup table (the connect summary reports 0 page loads); one payment can send two e-mails; the dispatcher sends mail inside the webhook request; tenant sites prune nothing.
- **Data lifecycle (step 08):** `data:prune` ships **disabled**; raw analytics events deleted at **90 days** only after their day is rolled up **and** their calendar-quarter report exists; quarterly report table kept forever; ledger 90/180 days; inbox 180/365; webhook payload dropped at 90 days; sessions/tokens after expiry; weekly footprint report; a test that fails on an unclassified log-like table.
- **Phases P0–P7** and the test matrix are in `09-rollout-and-testing.md`; **open questions OQ1–OQ24** in `10-open-questions.md`. **Answered 2026-10-19:** OQ18 (retention numbers), OQ21 (ship disabled, supervised first run), and OQ20/OQ24 treated as answered by OQ18. **Needed next:** OQ12 (marker name) before P0; OQ3, OQ7, OQ13 before P2.
- **Progress:** steps 00 and 01 are analysis (nothing to build). **Step 02 (the target model) is built and tested (2026-10-19, uncommitted in `w/www`):** `App\Services\Activity` (`Activity`, `EventDefinition`, `ActivityCatalog`, the `ActivityModule` contracts), `ActivityServiceProvider` (module list empty), the tables `laravel_notification_rules` and `laravel_notification_sends` with models, `RuleResolver`, `config/notifications.php` (`use_event_defaults`, default off), 58 new tests (all pass on MariaDB). Nothing uses it yet and no behaviour changed. Record: `plans/www/notifications_and_analytics/complete/02-target-model.complete.md`. **Step 03 (the admin inbox) is built and tested (2026-10-19, uncommitted in `w/www` and the SDK `portal.d.ts` copies in 7 apps):** extend-migration `2026_10_19_000002_extend_laravel_notifications.php`, `InboxChannel` + builder, repaired/extended notification API (route order, `unread-count`, `archive-all`, paging), the 3 producers write real columns, dashboard client escapes all text / uses the server unread count / paging / navigator registry / "Archive all"; 107 PHP + 37 TS tests pass (`npm run test:admin`). Not run on a server or in a browser. Record: `complete/03-admin-inbox.complete.md`. **Step 04 (the dispatcher extraction) is built (2026-10-19, uncommitted in `w/www`):** general `App\Services\Notifications\NotificationDispatcher` + `MailChannel` + `DefaultEmail`; Direct Payments is the first registered module (`app/Externals/DirectPayments/Notifications/DirectPayments*.php`; the old `NotificationDispatcher` class there is now a thin adapter with the same API); settings and ledger live in `laravel_notification_rules` / `laravel_notification_sends` (new copy migration `2026_10_19_000003_…`, old tables left untouched; old models deleted); 17 characterization tests written against the OLD dispatcher pass unchanged on the new one. One deliberate change: duplicates no longer count against the per-run send cap. Record: `complete/04-dispatcher-extraction.complete.md`. **Deploy order: run `migrate --force` before the new code serves traffic.** **Step 05 (analytics) is built and tested (2026-10-19, uncommitted in `w/www`):** migration `2026_10_19_000004_extend_laravel_analytics.php` (events gain `source`/`occurred_at`/value/`dedupe_key`/promoted grouping columns; rollup key widened; new `laravel_analytics_daily_dimensions`), `App\Services\Analytics` (`AnalyticsRecorder`, `AnalyticsAggregator`, `AnalyticsRollupService`, `AnalyticsSummary`, `BrowserVocabulary`, `AnalyticsColumnPromoter`), commands `analytics:rollup` (hourly) and `analytics:promote-columns`, the summary rewritten to read rollups + today, the public endpoint limited to a vocabulary / 4 KB / 20 keys / throttle per address and per site, the connect provider fixed, dead `FunctionsController` analytics functions removed, seed rows marked `seed`, the helper sends session and visitor ids, the dashboard shows "—" for a missing rate and has an Activity card. 95 PHP tests in `tests/Feature/Analytics` + `tests/Unit/Analytics`, 14 TS). **Nothing calls `AnalyticsRecorder` yet (step 06).** The optional reconciliation (05 §5.9) is not built. Record: `complete/05-analytics.complete.md`. **Deploy order: `migrate --force` first, then `php artisan analytics:rollup --backfill` (or wait for the first hourly run).** **Next step in order: 06 (`ActivityPublisher`).** The migrations reach the dev server with the next `migrate --force` or fresh install (not done).

- **Step 11 (module adoption) is built (2026-10-19, uncommitted in `w/www`):** Forms, Sponsorships, Ecommerce, Events, Appointments, Site Users and Subscribers announce through `ActivityPublisher`; Events, Appointments and Sponsorships now take payment like Ecommerce (the browser builds the invoice with `Portal.directPayments`; the module stores references only, has `attach` + `complete` and a ten-minute reconcile sweep over `PaymentGateway::paidInvoicesAwaiting`); Direct Payments names no module (gate G11); sponsors have a "My Payments" tab. Migrations `2026_10_19_000008`–`000010` (the last two **drop columns**: `paid_cents`, and the sponsorship amount/processor columns). Record: `complete/11-module-adoption.complete.md` (deviations, "found, not changed", what is unverified: no server, no browser, no real payment); off-plan fix E1 in `complete/00_extras.md`; OQ34–OQ36 were answered and built right after (event promo discount item, `appointments:expire-unpaid-bookings`, `require_payment` select, Ecommerce sweep scheduled). Next: M8 retirement one release after each module is live.

---

## 10. The `app()` refactor (done, committed `12975edab` in `w/www`)

The owner dislikes Laravel's `app(Foo::class)` service location ("hides too much, harder for the IDE to follow"). **118 of 129 calls in `w/www/app` were replaced** in 45 files: constructor injection for classes (controllers, services, middleware, an observer), `handle()` injection for jobs and commands (a command whose helper method used the service got a constructor instead), method parameters where a per-request extension point must be resolved fresh (`DirectPaymentAdvancedController::show`), `new` for stateless helpers with no constructor (`TierRegistry`, `ConnectProfileService`, `WebsiteContactUrl`), and `DirectPaymentCommunicationService` became an instance service (it was static only to call `app()`).

**Eleven calls stay on purpose:** three resolve a class name known only at runtime (`app($claimerClass)`, `app($contributionClass)`, `app($class)`), two break constructor **cycles** and carry a comment (`SwapService → ScheduledChangeService`, `EcommerceCartMergeService → EcommerceShopperListsService`), one is in a model that cannot take constructor parameters (`SiteUserConnection`), and five are Laravel state (`runningInConsole`, `isProduction`, `runningUnitTests`, `app('router')`).

**Traps found:** a constructor-injected controller keeps the object it first received for the life of the app instance — tests that rebind an extension point between requests need method injection; a test that calls a job's `handle()` directly needs `$this->app->call([$job, 'handle'])`; a dependency whose constructor touches the database would break every route of its controller (none did; two read config only). Tests still use `$this->app->instance()/bind()` fakes — injection keeps them working, `new X()` would not.

The owner's uncommitted `MembershipController` hunk is **not** in that commit (only the refactor's hunks were staged).

---

## 11. Repositories and commits

Commit only when told. Hashes from this session (all on the branch already checked out; nothing pushed):

| Repo | Commits |
|---|---|
| `w/www` | `12975edab` app() refactor · `1b0a0009b` laravel-cron.sh stop/start/remove, one entry ever · `da33d7c86` import-ready Direct Payments rows + signup that survives a welcome-e-mail failure · `5084afb16` audit step 14 + final fixes · `ba4cc079e` step 15 default e-mails · `fd738f297` step 13 (+ step 12 wizard notes) · `34c6654cf` test-fixture fixes · `bd73a39b5` step 10 · `bbfb108a6` step 09 |
| `w/members` | `17e56511` dashboard no longer sends `member_ref` · `2e9c9504` Connect sync reads `connect_user` (earlier, `create_member_redo`: `d78e5fae`, `7a4fda9f`, `dd6fbb7e`, `b0aab18f`, `92ac45d6`, `172be10c`) |
| `w/market` | `917361a` `portal.d.ts` drops `member_ref` · `094f198` payout client calls the renamed refund route |
| `packages/afsserver` | `4ab8bdfe` build_sites2.sh `--install-ecosystem`/`--destroy-db`/help default · `5068f023` full `--help` · `fbb27c1f` `--no-db-import` · `1c8685b3` `--update-repos`, `--deploy-type` · `198869c8` ownership + scheduler last · `b6178184` `desktop_updates` · `0cf32d93` extras E5 · `2df8a310` reports 11/14/15 + remote-run.sh · `25b3df68` reports 12/13 · `bf5a1a42`, `01b32544` reports |

**Uncommitted now (added 2026-10-19, step 02 of the notifications plan, `w/www`):** `bootstrap/providers.php`, `app/Services/Activity/`, `app/Services/Notifications/`, `app/Models/Notifications/`, `app/Providers/ActivityServiceProvider.php`, `config/notifications.php`, `database/migrations/2026_10_19_000001_create_laravel_notification_tables.php`, `tests/Unit/Activity/`, `tests/Unit/Notifications/`, `tests/Feature/Notifications/`, `tests/Support/FakeActivityModule.php`; and in `packages/afsserver`: `plans/markers.md`, the plan folder (now with `complete/02-…`). Earlier items: `w/www/app/Http/Controllers/Portal/Admin/MembershipController.php` (the **owner's** placeholder hunk — leave it); `packages/afsserver/plans/www/notifications_and_analytics/` (new folder); this `plans/handoff.md`. `w/` itself is not a repository, so `w/AGENTS.md`/`w/CLAUDE.md` cannot be committed.

---

## 12. What to do next (suggested order)

1. **Prove one payment end to end on dev** (§7.9): sandbox card → webhook → receipt e-mail. Report what the logs say; fix the cause in code *here*, not on the server. First check that an active mail provider row exists and that `vfPSdk2xj` (or the Direct Payment you pay on) has a handler or a `payment_receipt` setting.
2. **Answer the notifications-plan questions** (`10-open-questions.md`): OQ12 (marker), OQ3 (new tables + copy vs editing the create migration), OQ7 (defaults when nothing is configured), OQ13 (fold `payment_receipt` into `payment_succeeded`). Then say "start P0".
3. **Re-run the install on dev if wanted** to confirm the last script fixes (storage_device skips, ownership order): `--install-ecosystem --destroy-db --yes`, detached, with the owner's say-so. Or use `--update-repos` for code-only changes (it does not re-apply edited create migrations).
4. **Switch the signup page/SDK** to send the `sub_…` reference; update `portal.d.ts` copies in the same pass.
5. **Run `app_install.sh` for a real tenant on dev** (create a member, add a domain, deploy a website) and record what breaks — it has only been syntax-checked.
6. G2/G7 clean-ups (Direct Payments plan 11 §11.5); the dunning/staff readers (plan 14 §14.4); Square wallets-first.
7. Production (`--deploy-type prod`): Name.com production credentials and funds are in `plans/TODOS.md`; do not point a prod run at test data.

---

## 13. Earlier work, condensed (from the 2026-10-09 handoff)

- **domains_deploy** (`plans/domains_deploy`): see §8. Steps 02–11 built, 12 not; Name.com CORE v1 verified against its sandbox (quote, register idempotently, renew, nameservers; `lock(false)` is refused for 60 days after registration); decisions D1–D29 on the status board; the lab Brain VM ran old code and had no scheduler or queue worker. Open: D3 disputes, D11 website count, D13 per-tenant repo, D23 template pin, D24 scheduler on the Brain, D25–D28, member as registrant (D6, in `plans/TODOS.md`).
- **Direct Payments invoice-first review** (`plans/www/direct_payments/TODOS.md`): F1–F11 — an intent could be created with no invoice; `saveInvoice` took status and total from the request; line items were not checked against the catalogue; any caller could set a status; the webhook overwrote amounts and invented invoices; internal identifiers were sent to the provider; the caller chose the processor. Several were closed by the audit (server routing, `updateInvoiceStatus` no longer lets the browser mark an invoice paid); the P0–P2 list is still the reference.
- **Externals namespace refactor** (`plans/www/externals_namespace`, done, www `dcc9c387c`): 214 files moved under `app/Externals/*`, `app/Models/Externals`, `app/Http/Controllers/Externals/*`; verified by a PhpParser name-resolution oracle and an identical 730-route table. Drain the queues before deploying it (stored class names).
- **Externals security** (`plans/www/externals_security`, built, `enforce` is the default, **not deployed**): Laravel decides CORS for the externals API and Checkpoint; "Allowed websites" setting; 91 administrator routes got a login; 16 abuse-prone routes throttled; a route-policy test guards the route table (`RoutePolicy`). Deploy by first listing each site's embedding websites, or with `EXTERNALS_CORS_MODE=report`/`CHECKPOINT_CORS_MODE=report`, then switch to `enforce`.
- **Brain Flutter monitoring** (`plans/brain_flutter` 14–16): a small program on each server answers a phone on a port; built, **never run on a real server**; the Brain never logs into servers and nothing is pushed.
- **Storage enrollment** (`plans/storage-sharding/12`): coded in `www`, `storage_device`, `members`; unverified on a real server; registration must be automatic (no hand-copied keys).

---

## 14. Recipes

```bash
# --- local test database ---------------------------------------------------------------------------------
C:/Users/mrmit/mariadb/mariadb-11.4.5-winx64/bin/mariadbd.exe --defaults-file=C:/Users/mrmit/mariadb/data/my.ini --port=3307 --bind-address=127.0.0.1 --console     # run_in_background
C:/Users/mrmit/mariadb/mariadb-11.4.5-winx64/bin/mariadb-admin.exe -uroot -h127.0.0.1 -P3307 shutdown                                                                 # when done

# --- tests, with progress -------------------------------------------------------------------------------------
cd /c/projects/vagrant/afs-eco/w/www
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testdb_phpunit DB_USERNAME=root DB_PASSWORD= AFS_TEST_WIPE=1 EXTERNALS_CORS_MODE=off APP_ENV=testing
php artisan db:wipe --force                                              # clear leftovers between separate runs
nohup vendor/bin/phpunit tests/Feature/DirectPayments > /tmp/run.log 2>&1 &
for f in tests/Feature/Brain/*Test.php; do php -d max_execution_time=0 vendor/bin/phpunit "$f" | sed 's/\x1b\[[0-9;]*m//g' | grep -E "^OK|^Tests:" | tail -1; done
npm run test:external-api && npm run test:external-ui && npm run test:builder        # TypeScript (Node runner)
npx tsc --noEmit                                                         # www type check; members needs the temporary tsconfig (§3.7)

# --- the dev server (ask first, say each command) --------------------------------------------------------------------------
cd /c/projects/vagrant/afs-eco/packages/afsserver/bin
./remote-run.sh -r 'crontab -l -u apache'
./remote-run.sh -r 'ls -l /opt/afsserver/bin/build_sites2.sh; grep -c install-ecosystem /opt/afsserver/bin/build_sites2.sh'
sh /home/appfactorystudio/websites/dev/www/core/scripts/laravel-cron.sh --check --user apache --path /home/appfactorystudio/websites/dev/www      # on the server
bash build_sites2.sh --help desktop_updates                              # on the server or locally

# --- git, per repo, explicit working directory ----------------------------------------------------------------------------
git -C /c/projects/vagrant/afs-eco/w/www status --short
git -C /c/projects/vagrant/afs-eco/packages/afsserver log --oneline -10
```

---

## Appendix A — the memory files, verbatim

These are the owner's standing instructions and the lessons learned across sessions, exactly as saved in `C:\Users\mrmit\.claude\projects\C--projects\memory\` on 2026-10-19 (the index first, then one block per file). A new session should read the index and the files relevant to the task. Where a block names another memory as `[[name]]`, it is in this appendix.

### A.0 The index (`MEMORY.md`)

- **Deploy path: old way chosen** (`deploy-path-old-way-chosen.md`) — 2026-10-09 tenant deploys use members app_install.sh, not the new Brain agent; wildcard cert for sites names.
- **No proactive tool installs** (`no-proactive-tool-installs.md`) — don't install SDKs/runtimes/packages to test code; verify with stubs and let the user run the real install.
- **Externals portal.d.ts contract** (`externals-portal-dts-contract.md`) — never import the externals TS source; copy portal.d.ts and keep it current.
- **Ignore wwwafsportal_v1** (`ignore-wwwafsportal-v1.md`) — never read, edit, or analyze packages/wwwafsportal_v1; exclude it from estate-wide searches and counts.
- **Plan vocabulary: step = file, phase = work inside it** (`plan-vocabulary-step-phase.md`) — never say "unit"; P0/P1/P2 like the other plans.
- **Completion doc naming** (`completion-doc-naming.md`) — one per plan file, named after it; no per-phase files, no README in complete/.
- **Off-plan work goes in 00_extras.md** (`off-plan-work-goes-in-00-extras.md`) — non-plan-step fixes/seeders/regressions get a dated E* section in the module's complete/00_extras.md, not a numbered doc.
- **TS: no optional chaining, no any** (`ts-no-optional-chaining-no-any.md`) — assert deps explicitly and throw; directPayments.create() must be typed and return a typed object.
- **Never log in as the agent** (`never-login-as-agent.md`) — don't type credentials into a login form or reset an account's password myself, even local-dev seeded accounts, even for my own verification.
- **membership_change: decide per-resource** (`membership-change-decide-per-resource.md`) — retention policy for a resource is decided in its own features/[N]-[name].md when built, not batched upfront or called "blocking".
- **Test against real DB, not SQLite** (`test-against-real-db-not-sqlite.md`) — never rewrite production code (e.g. MySQL-only SQL) for SQLite test compatibility; afs-eco repos run MySQL everywhere real.
- **Never auto-switch .env** (`never-auto-switch-env.md`) — never run use-env.sh automatically or change an app .env because of an outside factor (lab start, sync); only warn.
- **Lab security is not a concern** (`lab-security-not-a-concern.md`) — the Vagrant labs are private/disposable; never raise security warnings about keys, certs, open DB, or what's committed/pushed there.
- **Never config:cache** (`never-config-cache.md`) — estate never runs php artisan config:cache, prod included; no caveats/steps assuming it.
- **Run tests at the end** (`run-tests-at-the-end.md`) — on big multi-phase work, write everything first; run the test suite once at the end (analyze is fine between chunks).
- **Limit CPU, kill leftover processes** (`limit-cpu-and-kill-leftover-processes.md`) — one heavy process at a time, below-normal priority, always kill Electron/node leftovers; the user's PC froze twice.
- **afsbrain: no dart format** (`afsbrain-no-dart-format.md`) — `dart format` rewrites whole committed files at 80 columns; edit by hand in the long-line style, check with analyze only.
- **lab2 is reachable: test, don't assume** (`lab2-is-reachable-test-dont-assume.md`) — HTTPS/SSH/MariaDB answer; documented read-only SSH key in lab2/ssh; never claim unreachable untested.
- **Server monitoring: direct to phone, not via Brain** (`server-monitoring-direct-not-via-brain.md`) — SSH from phone on demand; no push agent, Brain never SSHes, reboot yes, no alerts/Firebase.
- **Monitoring built, unverified on a server** (`monitor-built-offline-mirror-verification.md`) — plans 14, 15, 16 done (program 1.3.0, upgrade servers); lab exit checks and BS27 PHPUnit need say-so; test pitfalls.
- **Windows lab ssh + members tsc gotchas** (`windows-lab-ssh-and-tsc-gotchas.md`) — send lab scripts on stdin, not as quoted args; members tsc needs a temp ignoreDeprecations override; no local jq.
- **Direct Payments: follow the playground samples** (`direct-payments-follow-the-samples.md`) — browser builds the invoice with the SDK, server only verifies; every charge = an invoice of catalogue items; say "the provider", never a provider's name.
- **Domains: everything from Brain settings** (`domains-everything-from-brain-settings.md`) — no .env/defaults/fallbacks for domains; ssh by password via bash+expect; Name.com CORE v1; D6 platform registrant for now.
- **Plain language, name the language and file** (`plain-language-and-name-the-file.md`) — say PHP vs TypeScript and link the file; answer the question first; no "browser is untrusted" lectures.
- **afs-eco repos and commit rule** (`afs-eco-repos-and-commit-rule.md`) — w/ is not a git repo; commit only when told, per repo; test DB command; refactor tooling lives in the scratchpad.
- **Remote agent never edits code** (`remote-agent-never-edits-code.md`) — only I change app code; server work goes to tmp/handoff-share-remote.md (config + read-only checks only).
- **Registration must be automatic** (`registration-must-be-automatic.md`) — no hand-copied keys/.env; Brain and storage app exchange secrets (plan step 12); use afs-website/RunCommandBG/commands.sh/SshRunner for server work.
- **Storage enrollment built, unverified** (`storage-enrollment-built-unverified.md`) — plan step 12 coded in www/storage_device/members (2026-10-11), uncommitted, never run on a real server; sqlite env-override test recipe and known pre-existing failures.
- **locales.json script edits** (`locales-json-script-edits-anchor-and-verify.md`) — anchor regexes to line start + exact indent, diff against a baseline; a loose regex broke 12 keys on 2026-10-13 (recovered from dist bundle).
- **Heredocs eat backslashes** (`shell-heredoc-eats-backslashes.md`) — write regex/escape-heavy code with Write/Edit, not Bash heredocs or node -e patches; verify backslashes survived.
- **Local MariaDB for tests** (`local-mariadb-for-tests.md`) — permanent portable MariaDB in C:\Users\mrmit\mariadb (port 3307); start/stop scripts, test env vars.
- **No app() service location** (`no-app-helper-service-location.md`) — inject via constructor/handle()/method params; keep app() only where nothing else works (www commit 12975edab).
- **Show me = show the code** (`show-me-means-show-the-code.md`) — paste the real code with path:line in order; no diagrams unless asked.
- **Run tests with progress** (`run-tests-with-progress-output.md`) — background log with progress/testdox, never --no-progress; wipe testdb_phpunit between runs.
- **Notifications + analytics plan** (`notifications-analytics-plan.md`) — plans/www/notifications_and_analytics written 2026-10-19 (+ data lifecycle step 08), planning only, OQ1–OQ24 open.



### A.1 `afs-eco-repos-and-commit-rule.md`

```markdown
---
name: afs-eco-repos-and-commit-rule
description: "afs-eco layout: w/ is NOT a git repo (ten independent repos under it); commit only when told, per repo; which repos hold what; refactor tooling lives in the session scratchpad."
metadata:
  node_type: memory
  type: reference
  originSessionId: a1b6935b-ca69-4374-8bbc-153b50532b0d
  modified: 2026-10-09T17:04:32.512Z
---

- `C:\projects\vagrant\afs-eco\w\` is **not** a git repository. Its repos: `w/www` (the Brain, Laravel), `w/members`, `w/auth` (Checkpoint, the authentication layer for members and apps), `w/market`, `w/coordinator`, `w/communications`, … (ten in all). `w/AGENTS.md` and `w/CLAUDE.md` are therefore **untracked** — edits there cannot be committed.
- Plans and docs: `C:\projects\vagrant\afs-eco\packages\afsserver` (repo `afsserver`; plans in `plans/`, nginx generation in `generations/http`, scratch config in `tmp/`).
- The Vagrant lab: `C:\projects\vagrant\lab2` (its own repo; mounts `afs-eco` at `/vagrant/afs-eco`).
- **Never commit/branch/push on my own initiative.** Commit only when told ("commit", "commit everything"), one commit per repo, message ending with the attribution line from the system reminder, `git add` explicit paths or `-A` only after checking `git status`. The owner sometimes tells me to commit a finished batch first so the next refactor is a clean diff.
- Tests run with the lab test DB: `DB_HOST=10.0.0.243 DB_DATABASE=testdb_phpunit_www DB_USERNAME=root DB_PASSWORD= vendor/bin/phpunit …` (the DB keeps tables from earlier runs; many "table already exists" errors are leftover state, not code). Opt-in lab tests: `AFS_LAB_WEB=1`, `AFS_LAB_DNS=1`, `AFS_LAB_BRAIN=1`.
- Large mechanical refactors (namespace moves) were done with a PhpParser tool + before/after checks (name-resolution oracle, `route:list` table comparison); the scripts are in the session scratchpad (`…\scratchpad\externals_refactor.php`, `controllers_refactor.php`) and are NOT in any repo — re-create from `plans/www/externals_namespace/*.md` if needed.
- Windows traps: Git Bash heredocs break on apostrophes/backslashes (write script files with the Write tool); `taskkill` of php processes needs the owner's say-so; `tar` needs `--force-local` for `C:` paths.

Related: [[never-auto-switch-env]], [[run-tests-at-the-end]], [[windows-lab-ssh-and-tsc-gotchas]].
```


### A.2 `afsbrain-no-dart-format.md`

```markdown
---
name: afsbrain-no-dart-format
description: "Never run `dart format` on afsbrain_flutter files — the project uses long lines and the formatter rewrites whole committed files at 80 columns."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 94c4bc94-17fc-4eb5-bb60-8933e79e5904
  modified: 2026-10-05T13:06:24.967Z
---

Don't run `dart format` (or `flutter format`) on files in `afsbrain_flutter`.

**Why:** the project's code style is long single lines (no 80-column wrapping). Running `dart format` on one committed file turned a ~30-line edit into a 390-line diff and introduced new lint infos; it had to be restored with `git checkout -- <file>` and the edit reapplied by hand (2026-10-05, `member_list_page.dart`).

**How to apply:** make edits by hand in the surrounding style and check with `flutter analyze` only. If a committed file was reformatted by accident, restore it from git and redo the change. Multi-line edits to Dart/PHP go in a script file in the scratchpad, because Git Bash heredocs break on apostrophes.
```


### A.3 `completion-doc-naming.md`

```markdown
---
name: completion-doc-naming
description: A completion doc is named after the plan file it completes; one per file, never one per phase, never a README in complete/.
metadata:
  type: feedback
---

`plans/<area>/complete/<NN>-<same name as the plan document>.complete.md` — one record per
plan file, H1 `# NN — Title (status)`, opening with a `## Status` section.

Verified across events, forms, appointments, ecommerce, site_users, members_brain,
membership_change and billing: eight folders, no exceptions.

**Write it only when the step is FINISHED.** A completion document appearing in `complete/`
is the signal that its plan file is done — writing one while phases are still outstanding
makes progress reports read as "step complete" when they are not. Keep in-progress notes
outside `complete/` and land the record at the end.

**Do not** split a record into one file per phase, and **do not** put a `README.md` in
`complete/`. A file whose work lands over several phases keeps ONE record and grows it, with
a phase table saying what is done.

**Why:** the per-phase split was invented once for `alloc-v2-parse-direct/complete/` and is
wrong there too. Taking it as precedent produced two rounds of wrongly-named files.

**How to apply:** before writing any completion doc, list a neighbouring `complete/` folder
and copy its shape. See [[plan-vocabulary-step-phase]].
```


### A.4 `deploy-path-old-way-chosen.md`

```markdown
---
name: deploy-path-old-way-chosen
description: "2026-10-09 owner chose the OLD shell deploy (members core/scripts, app_install.sh) for tenant sites over the new Brain runner/agent; don't push the new flow."
metadata:
  node_type: memory
  type: project
  originSessionId: eb81e185-5769-4dae-9b0d-44d4d984abcd
  modified: 2026-10-09T22:00:25.957Z
---

Owner decision 2026-10-09: tenant website deploys go back to the **old way** (members `DomainController::createWebsite` -> `background_runner.php` -> `commands.sh` -> `app_install.sh`), not the new Brain `DeployRunner` + `afs-tenant-agent`.

**Why:** the new flow has unknowns. It never delivers `subdomain_properties` (app_install.sh ~line 595 inserts them at build time from members' membership data; the tenant cannot work without them), it drops the `www` / `www-own` split (owner found `SetEnv APP_URL` breaks assets; each copy needs its own `.env` APP_URL + ASSET_URL), it has no certbot, and the template contract (T1-T8) is unchecked.

**How to apply:**
- Fix the old path rather than the agent: its Brain calls are unauthenticated and now 401; lab `SUBDOMAIN_PROPERTIES_SUBDOMAIN=www15` maps to a missing `dev/members15` path; app_install.sh hard-codes BRAIN_API_URL; tenant `.env` points at dev Checkpoint.
- Certificates: sites name moves to a **wildcard** `*.sites.appfactory.studio` (Let's Encrypt per-registered-domain limit); `LETSENCRYPT_CHECK=false` stays for the lab. Custom-domain certbot must only run for an active, proven claim (domain claims from domains_deploy step 02).
- Domain claims (one active claim, proof of control) stay; a website is tied to a domain.
- Related: [[domains-everything-from-brain-settings]]
```


### A.5 `direct-payments-follow-the-samples.md`

```markdown
---
name: direct-payments-follow-the-samples
description: "Direct Payments is an already-built API: implement against it exactly like public/playground/externals/direct-payments (browser builds the invoice with the SDK, server only verifies); say 'the provider', never a provider's name."
metadata:
  node_type: memory
  type: feedback
  originSessionId: a1b6935b-ca69-4374-8bbc-153b50532b0d
  modified: 2026-10-09T17:04:13.624Z
---

Direct Payments (`w/www/app/Externals/DirectPayments`, SDK `resources/js/external/api/direct_payments`) is **finished platform API**. Do not invent a parallel path.

- **The pattern is the playground samples** (`w/www/public/playground/externals/direct-payments/`, esp. `server/README.md`, `server/checkout-and-post.js`, `validate-order.php`, and the ecommerce façade `attach` / `complete` / reconcile): the **browser** builds the invoice with the SDK (`createInvoice` → `addItem({item_ref, quantity, amount})` on a catalogue item, DYNAMIC for variable prices → `lock` → `setupSinglePayPayment` → `confirmPayment`), then POSTs **three references** (invoice, Direct Payment, processor) to the façade; the server only asks `PaymentGateway::isPaid()/snapshot()` and stores the three refs. The server never builds the invoice and never receives a price it believes.
- **Every charge goes through an invoice of Direct Payment catalogue items.** The invoice is what is reviewed, refunded and reported on. (The platform currently has holes here — filed in `plans/www/direct_payments/TODOS.md`.)
- **Say "the provider"**, never "Stripe"/"PayPal"/"Square", when talking about the charging side: they differ, Direct Payments hides that, and the provider must know nothing about our internal system (no invoice refs/metadata sent to it).
- The SDK is public and unauthenticated by design (it runs on any website), so the protection is server-side: server prices from the catalogue, "paid" only from the provider's own confirmation/webhook.

**Why:** I built the domains purchase with a PHP-created invoice and a made-up catalogue-less line; the owner stopped it twice ("STOP deviating", "creating the invoice should have been done on the client"). Fixed 2026-10-09 (domains_deploy `complete/00_extras.md` E11).

**How to apply:** before touching anything that charges money, read the playground `server/` folder first; mirror it. Related: [[ts-no-optional-chaining-no-any]], [[externals-portal-dts-contract]].
```


### A.6 `domains-everything-from-brain-settings.md`

```markdown
---
name: domains-everything-from-brain-settings
description: "domains_deploy: every domains setting comes from the Brain settings document (brain_config, controller.domains) — no .env, no defaults, no fallbacks; servers are reached by ssh password via bash + expect, no keys."
metadata:
  node_type: memory
  type: project
  originSessionId: a1b6935b-ca69-4374-8bbc-153b50532b0d
  modified: 2026-10-09T17:04:20.080Z
---

Standing owner instructions for `plans/domains_deploy` (2026-10-08/09):

- **All domains settings live in the Brain settings document** (`brain_config` row `reference_num='default'`, JSON under `controller.domains`, edited through the json-editor schema `w/www/resources/js/portal/admin/afscore/schema/brain_controller_schema.ts`). Read only through `App\Services\Brain\Domains\DomainSettings::get('a.b')`. **No `.env` keys, no defaults in code, no fallbacks** — a missing setting stops with `DOM_E_SETTING_MISSING` (503) and names the setting. `php artisan domains:settings` checks them. There could be hundreds of settings; the `.env` must not carry them. (Two things are not Brain settings: the Direct Payment reference comes from the browser, per [[direct-payments-follow-the-samples]]; credentials for registrars come from the Brain's `controller.keys` entry, e.g. `namecom` with `production`/`development` slots.)
- **Reaching other servers: ssh with the login stored on each server's own record, by password, driven by bash with `expect` answering the prompts** (`w/www/core/scripts/ssh/afs-ssh-run` + `.expect`). **No ssh keys to manage.** Production servers allow password login; plain-text passwords in the database are acceptable (owner closed D20).
- **Name.com**: the driver speaks CORE v1 (`/core/v1`), keys from the Brain; sandbox username = normal username + `-test` with its own token. Registrant of record is the platform for now; the member as registrant is filed in `plans/TODOS.md` (D6).
- The owner reads plain language: no jargon, say which language/file (PHP vs TypeScript) — see [[plain-language-and-name-the-file]].

**Why:** the owner was angry when I introduced env keys (`DOMAINS_TENANT_SSH_*`) with no way to get the values; they want everything dynamic through the dashboard.

**How to apply:** when adding a domains setting, add it to `DomainSettings::KEYS`, the json-editor schema, the test fixture (`tests/Support/DomainSettingsFixture.php`) and the lab seeder (`php artisan domains:seed-lab`); never read `env()` for domains. Related: [[never-auto-switch-env]].
```


### A.7 `externals-portal-dts-contract.md`

```markdown
---
name: externals-portal-dts-contract
description: Consumers of the AFS externals API must never import its TypeScript source; they use a copied portal.d.ts ambient declaration instead.
metadata: 
  node_type: memory
  type: project
  originSessionId: bdab0308-3c3a-487d-bd63-b71d2f4c398e
  modified: 2026-08-25T04:51:20.146Z
---

In afs-eco, **never link or import a consuming TypeScript file to the externals API source**
(`www/resources/js/external/**/*.ts`). The externals API is consumed **only** at runtime via
`window.Portal.*`.

Type safety comes from **`www/resources/js/external/portal.d.ts`**, which is the source of
truth and is **copied** into each consuming project — the established location is
`<app>/resources/js/checkpointHelper/static/portal.d.ts` (present in coordinator,
dream_graph, flow, notes, pdf_editor, market, members).

**Whenever the externals API surface changes, update `portal.d.ts` in the same pass** —
it is a hand-maintained mirror, not generated, so an added method that is not declared there
is invisible to every consumer's build.

**Why:** the externals API is bundled and served independently (`webpack.config.cjs` in
`resources/js/external/`); importing its source would pull a second copy into the consuming
app's bundle and couple the two build graphs. The `.d.ts` gives compile-time types with zero
runtime linkage.

Related: [[billing-plan-afs-eco]]
```


### A.8 `ignore-wwwafsportal-v1.md`

```markdown
---
name: ignore-wwwafsportal-v1
description: "Never read, edit, or analyze packages/wwwafsportal_v1 in the afs-eco workspace — treat it as out of scope always."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: d689b178-d6fb-4390-8351-ef559033e43d
  modified: 2026-08-27T00:24:09.637Z
---

`C:\projects\afs-eco\packages\wwwafsportal_v1` is permanently out of scope. Never read,
edit, analyze, or include it in searches, audits, counts, or plans.

**Why:** The user stated it directly as a standing rule, not a one-off. It is a `_v1`
package that still contains files mirroring live ones elsewhere in the estate (e.g. its own
copies of `checkpoint_registry_v3.json` and `alloc_v2.ts`), so it shows up in estate-wide
greps and inflates findings with results the user does not want acted on.

**How to apply:** Exclude it from `find`/`grep`/Glob the way `node_modules` and `vendor` are
excluded. When reporting counts of files across the estate, exclude it and count from the
remaining trees. If something in it looks relevant, say so briefly rather than reading it.
Related: [[externals-portal-dts-contract]].
```


### A.9 `lab-security-not-a-concern.md`

```markdown
---
name: lab-security-not-a-concern
description: "The Vagrant labs (lab1/lab2) are a private, disposable home-LAN environment with no real data; don't raise security concerns about keys, certs, open ports, blank DB passwords or what gets committed/pushed there."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 6bae9060-8692-4130-8df0-6380ddb33755
  modified: 2026-09-30T17:09:07.570Z
---

Do not flag, warn about, or propose fixes for security issues in the Vagrant labs (`C:\projects\vagrant\lab1`, `lab2`): committed/pushed private keys (ssh keys, mkcert `*-key.pem`), blank MariaDB root, no firewall, open ports, SQL dumps in the repo, and similar. The user has said this more than once (2026-09-29 and 2026-09-30): the labs are a private LAN nobody else can reach, hold no real data, run only sometimes, and everything can be thrown away and recreated.

**Why:** repeating the warnings annoyed the user and added nothing. They chose the relaxed posture deliberately ("nothing can be stolen and nothing can be hacked").

**Exception (2026-09-30):** the user asked for a production-security guide, `lab2/security.md` (firewalld ports, SSH, DB, nginx, SELinux deliberately disabled, etc.). That file is where production hardening lives; add new measures to it as the projects grow, keep each one off by default / production-only, and never apply any of it to the labs. The lab itself stays open.

**How to apply:** when committing, pushing, copying folders between computers, or describing the labs, just do the task and stay silent on security. Don't add `.gitignore` rules for keys/certs to lab repos, don't suggest rotating keys or rewriting git history for them, and don't phrase lab design choices as security trade-offs. This is about the lab environment only; it does not loosen other rules (e.g. [[never-login-as-agent]], and not leaking credentials from real app environments).
```


### A.10 `lab2-is-reachable-test-dont-assume.md`

```markdown
---
name: lab2-is-reachable-test-dont-assume
description: "lab2 (HTTPS, SSH, MariaDB) is reachable from this PC; test reachability before ever saying a lab or DB is unreachable, and use the documented read-only SSH key."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 94c4bc94-17fc-4eb5-bb60-8933e79e5904
  modified: 2026-10-06T14:05:02.744Z
---

lab2 VMs (`dns` 10.0.0.242, `brain` 10.0.0.243, `web` 10.0.0.244) answer on HTTPS, SSH (22) and MariaDB (3306) from this PC. The lab's own `lab2/REMOTE-ACCESS.md` documents read-only SSH as `vagrant@<ip>` with the key at `C:\projects\vagrant\lab2\ssh\lab2_id_ed25519` (use `ssh -i … -o BatchMode=yes`); it says read-only unless the human says otherwise, never print or store a password, and MariaDB root has no password (do not modify data with it).

**Why:** I once wrote (in a summary and then to the user) that no MySQL was reachable from here and so no server PHPUnit file could be run. That was an untested assumption and it was wrong; the user rightly asked why I could not reach lab2. DEVELOPMENT_MODE is `vagrant`, so verifying end to end is expected.

**How to apply:** before claiming a lab, database or server is unreachable, try it (`curl -sk`, `bash -c '</dev/tcp/ip/port'`, a read-only `ssh` command). Prefer reading real facts from lab2 over inferring from scripts. Changes (restart, edit, install, load generation) still need the user's say-so. The five server PHPUnit files of plan 13 may be runnable against the lab MariaDB; I have not tried.
```


### A.11 `limit-cpu-and-kill-leftover-processes.md`

```markdown
---
name: limit-cpu-and-kill-leftover-processes
description: "Never leave Electron/node/test processes running and never run several heavy processes at once; the user's PC freezes and they have closed the app because of it."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 3e8cc159-4cf3-475c-abe4-4e8e8a32260b
  modified: 2026-10-05T01:12:19.148Z
---

Run heavy work (webpack builds, Electron/Playwright launches, flutter tests/builds) strictly **one at a time**, at below-normal priority (`cmd //c "start /belownormal /wait /b cmd /c ..."`), and **always kill what a run started** before reporting or moving on.

**Why:** On 2026-10-05 repeated Electron diagnostics left `electron.exe` renderer/GPU children running after a crash or a timeout (a hung `diag_*.js` run, a second launch that never exited), and parallel builds/tests saturated the CPU; the PC froze and the user had to close the program, twice ("you keep leaving electron processes running").

**How to apply:**
- After any Electron/Playwright run, list leftovers with `wmic process where "name='electron.exe' or name='node.exe'" get ProcessId,CommandLine` filtered on `afsdesktop5|afs-diag|afs-timesheet` and delete them (`wmic process where "name='electron.exe' and commandline like '%afs-diag%'" delete`). Check `tasklist | grep -ci electron` is 0 before saying a task is done.
- Put a hard `timeout` on every script, and never start a long Electron experiment in the foreground without a cleanup step that runs on failure too.
- Prefer reading code and unit tests over launching the real app; ask before starting a long chain of app runs. One build, then one script at a time.
- Remove the throwaway profile folders (`%TEMP%\afs-diag-*`, `afs-timesheet-*-smoke-profile-*`) and `.tmp*` in the repo.
Related: [[run-tests-at-the-end]].
```


### A.12 `local-mariadb-for-tests.md`

```markdown
---
name: local-mariadb-for-tests
description: "A permanent portable MariaDB 11.4.5 lives in C:\\Users\\mrmit\\mariadb (port 3307); start it to run www tests on a real database, stop it afterwards."
metadata:
  node_type: memory
  type: reference
  originSessionId: efb40551-75a8-492e-a5d4-c3770bbf5f41
  modified: 2026-10-17T09:08:57.594Z
---

Installed 2026-10-17 at the owner's request ("permanently install mariadb so that you can use it anytime"). Portable, no Windows service, no admin.

- Start: `cmd //c C:\Users\mrmit\mariadb\start-mariadb.cmd` (below-normal priority, 127.0.0.1:3307, root, no password). Stop: `stop-mariadb.cmd`. Database `testdb_phpunit`.
- Run www tests: in `w/www`, `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testdb_phpunit DB_USERNAME=root DB_PASSWORD= AFS_TEST_WIPE=1 EXTERNALS_CORS_MODE=off vendor/bin/phpunit tests/Feature/...` (the lab MySQL at 192.168.56.11 is usually down; `EXTERNALS_CORS_MODE=off` is needed or the existing suite gets 401/403).
- Full 7-folder run (DirectPayments, Processor, SiteUsers, Connections, Ecommerce, Events, Membership) takes several minutes: run it in the background.
- Always stop the server when finished (one heavy process at a time, see [[limit-cpu-and-kill-leftover-processes]]). SQLite in-memory is only a smoke check: it misses column-length and FK-timing bugs MariaDB catches.
```


### A.13 `locales-json-script-edits-anchor-and-verify.md`

```markdown
---
name: locales-json-script-edits-anchor-and-verify
description: "Editing afsdesktop5 src/renderer/locales.json by script — anchor every regex to the line start and exact indentation, and prove afterwards that only the intended keys changed."
metadata:
  node_type: memory
  type: feedback
  originSessionId: f0916288-2c2f-46e3-b6f7-ad1b4f8c1edc
  modified: 2026-10-13T12:08:24.580Z
---

When a script edits `src/renderer/locales.json` (2-space JSON, four languages, hundreds of nested keys), anchor every search with `^…$` and the `m` flag **and the exact indentation**, and never rely on "6 spaces + key" as a substring.

**Why:** on 2026-10-13 a cleanup regex `/      "templates": .*\n/g` (meant for pdfDocs) also matched the *tail* of deeper lines (`timesheet.settings.templates`, `timesheet.settings.sections.templates`, `timesheet.editor.templates`) in all four languages, deleted them and left the file unparseable. There is no git history in this repo to restore from. It was recovered because `dist/main/index.js` still embedded the previous locales as a webpack `eval("…JSON.parse('…')")` module (extract it, evaluate the string literal, compare flattened keys).

**How to apply:** (1) copy the file to the scratchpad before the first edit; (2) after the edit parse it and compare the flattened key/value maps with the baseline — the only differences must be the keys you meant to add; (3) edit scripts that need regex-heavy text with the Edit/Write tools, not through a shell heredoc (backslashes and quotes get rewritten); (4) insert new sections by text at a known marker line (`    "pdfLib": {`) rather than re-serialising, which reorders numeric keys.
```


### A.14 `membership-change-decide-per-resource.md`

```markdown
---
name: membership-change-decide-per-resource
description: "In membership_change, a resource's retention/regression policy is decided feature-by-feature in features/[N]-[name].md when that resource's feature is actually being built — never pre-decided in a batch, and never framed as 'blocking' other work."
metadata:
  node_type: memory
  type: feedback
  originSessionId: b95fc97d-8194-4c7a-ab32-81570f24d03a
  modified: 2026-09-23T18:02:14.529Z
---

The user's actual workflow for `packages/afsserver/plans/membership_change`: features get
built first, with no limitations attached. Only once a feature exists does the user go into
its own `features/[N]-[name].md` (numbered per `TODO-member-resources.md`) and decide that
resource's retention/regression policy — what "retire" does to it, grace, purge, who gets
notified.

Pushed back hard (2026-09-23) when I tried to batch-decide open questions
(`voip_numbers_count`, `secondary_users`, `dreamgraph_graph_nodes`'s notify/retention
combination) ahead of any implementation work on those resources, and again when the plan
docs (`00-overview.md`, `05-rollout-and-testing.md`) labeled these "blocking" — from the
user's perspective nothing is blocked, since they decide themselves when to pick up each
resource. "Blocking" as a framing assumes other engineering work is gated on the decision;
it isn't, because the user sequences the work themselves.

**Why:** batching these decisions removes the user's own sequencing control and produces
plan-doc content divorced from real implementation context — a resource-specific retention
question is only real once the feature exists to attach it to.

**How to apply:** engine-wide/cross-cutting architecture questions that don't belong to one
resource (e.g. whether the forward edge asks Parse or a live inventory) are still fine to
resolve ahead of implementation. But anything of the shape "what happens when resource X
retires/purges/regresses" belongs in that resource's own `features/[N]-[name].md`, written
only when that feature is actually being built or the user explicitly asks for it — never
pre-decided, and never described as "blocking" other work in the plan docs. If a resource's
own feature doesn't exist in the codebase yet, say so plainly in that doc rather than
proposing a retention design for a feature that isn't there. Related:
[[completion-doc-naming]], [[off-plan-work-goes-in-00-extras]],
[[plan-vocabulary-step-phase]].
```


### A.15 `monitor-built-offline-mirror-verification.md`

```markdown
---
name: monitor-built-offline-mirror-verification
description: "Plan 14 (server monitoring) was built 2026-10-06 but never run on a server or device; the app depends on crypto, flutter_secure_storage, dartssh2 that the user must `flutter pub get`; analyze/tests were run in an offline mirror with a dartssh2 stub."
metadata:
  node_type: memory
  type: project
  originSessionId: 94c4bc94-17fc-4eb5-bb60-8933e79e5904
  modified: 2026-10-06T22:02:17.159Z
---

Plan 14 (P17 lights, P18 program + install, P19 server view) is built in `afsbrain_flutter/lib/features/monitor/` and `w/www/core/scripts/monitor/`. Completion doc: `packages/afsserver/plans/brain_flutter/complete/14-server-monitoring-foundation.complete.md`.

**Why this matters:** nothing was run as root on a server or on a phone. The installer (`install.sh`) and the real `dartssh2` API are unverified; the lab exit checks need the user's say-so before any change to lab2.

**How to apply:**
- The user must run `flutter pub get` (pubspec gained `crypto`, `flutter_secure_storage`, `dartssh2`); I must not install packages myself ([[no-proactive-tool-installs]]).
- To analyze/test before that, use the offline mirror: `scratchpad/mirror_setup.sh` copies lib/test/assets to `scratchpad/mirror`, resolves from the local pub cache with a stub `dartssh2`, then `flutter analyze` / `flutter test` there (set `AFS_MONITOR_PROGRAM` to the PHP file so the Dart-to-PHP tests run). Never run the sync while a mirror test run is going (it deletes lib/test).
- The app carries copies of the program files in `assets/monitor/`; run `tool/sync-monitor.sh` after editing anything in `w/www/core/scripts/monitor/` (gate G10 fails otherwise).
- PHP tests: `php core/scripts/monitor/tests/run-tests.php` and `run-collector-tests.php`; read-only collector check on a lab VM works by sending the program on stdin to `php` (see the scratchpad `lab_collect.py` pattern), which writes nothing there.
- Shell heredocs with apostrophes/backslashes keep breaking in this harness: write Python patch scripts with the Write tool, or use Edit.

Related: [[server-monitoring-direct-not-via-brain]]

**Plan 15 (2026-10-06):** live view (P20), CPU/RAM attribution (P21) and network attribution (P22) are built; the program is **1.2.0** (servers need upgrading from the Program screen) and `pub get` has been run since (the offline mirror is no longer needed). Completion doc: `complete/15-server-live-and-attribution.complete.md`. Read-only lab runs: send the program text on stdin to `php` over the lab ssh key with its main guard replaced by a driver (scratchpad `lab_attr.py` pattern); an unprivileged user cannot read `/proc/PID/io`, others' `smaps_rollup` or `ss -p` owners, so those parts are unverified until the root-installed program runs. N2 (per-site web traffic) is NOT built pending the user's yes. Test pitfalls hit: a Dart record with a `List` or a matcher (`isEmpty`) inside never compares equal in `expect`; after a cubit `emit` a widget test needs two `pump()`s (or `pumpAndSettle`); `Map` literals in fakes need `<String, Object?>` element types if a test sets a field to null.

**Plan 16 (2026-10-06):** control is built (program **1.3.0**, class `Controls` in `afs-monitor.php`; app `ControlFlowCubit`, Logs, Recent actions; Brain BS27 audit route behind `BRAIN_MONITOR_CONTROL`, off by default). Completion doc: `complete/16-server-control.complete.md`. **Never run on a real server**; `systemd-run` from inside the program's sandbox is untested. **`BrainStaffMonitorAuditTest` and the audit migration were NOT run: `phpunit.xml` points at the lab database (192.168.56.11 / testdb_appfactorystudio), so DB-backed PHPUnit needs the user's say-so.** N2 per-site traffic is now `enable_site_log` (off until chosen per server). Test pitfalls hit: `SharedPreferences.setMockInitialValues({})` is needed in any widget test that awaits `DangerousSwitch.set` (it hangs otherwise); a spinner never lets `pumpAndSettle` finish, use two `pump()`s; `SelectableText` on short lines fails the 48 dp tap-target guideline, so wrap the screen in `SelectionArea` with plain `Text`; run `tool/sync-monitor.sh` again after ANY program edit or `monitor_rules_test` fails.
```


### A.16 `never-auto-switch-env.md`

```markdown
---
name: never-auto-switch-env
description: "Never run use-env.sh automatically or let any script change an app .env based on an outside factor (which lab is starting, a sync, etc.); only print a warning."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 6bae9060-8692-4130-8df0-6380ddb33755
  modified: 2026-09-30T14:22:01.932Z
---

Never run `afs-eco/packages/afsserver/bin/use-env.sh` automatically, and never let a script rewrite an app's live `.env` because of some outside factor (which lab is being started, which computer a VM is placed on, `sync`, provisioning). When the estate's hostnames don't match the lab being used, **print a warning and carry on**; the user switches `.env` files by hand.

**Why:** the user said so explicitly (2026-09-30) while lab1/lab2 share one `afs-eco` checkout, each app having a single `.env` plus baked `VITE_*` bundles. Silently switching them would break whichever lab was relying on the old values, and the live files also carry hand-added keys that `_env/vagrant/.env` lacks.

**How to apply:** in lab scripts, only read `.env` (e.g. `w/www/.env` `APP_URL`, `w/communications/.env` `CHECKPOINT_BASE_URL`) and warn. Copy/sync steps must exclude the live `.env` files and must not overwrite an existing `communications/.env`. Running `use-env.sh` myself is only OK when the user explicitly asks for that switch in that message (as with the `vagrant-lan` apply); `--dry-run` is harmless. Generating `_env/<mode>/.env` source files (make-lan-env.sh / make-env.sh) is fine — those are not the live `.env`.
```


### A.17 `never-config-cache.md`

```markdown
---
name: never-config-cache
description: "The estate never runs php artisan config:cache (any environment, prod included); don't recommend it or write caveats about it."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 6bae9060-8692-4130-8df0-6380ddb33755
  modified: 2026-09-30T20:18:44.452Z
---

Never run or recommend `php artisan config:cache` anywhere in the estate, production included. Don't add docs/caveats that assume it.

**Why:** user said emphatically "WE NEVER EVERY RUN php artisan config:cache" after I listed "don't use config:cache yet" as a pending item and put `config:cache` in security.md's deploy steps.

**How to apply:** `env()` outside `config/` is fine here; don't flag it as a risk or as remaining work. Use `config:clear` when switching modes. See [[never-auto-switch-env]].
```


### A.18 `never-login-as-agent.md`

```markdown
---
name: never-login-as-agent
description: "Never type credentials into a login form or reset an account's password myself, even a local-dev/seeded account, even for my own verification."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 9fde595e-9d74-451c-bc98-8fa98c2b6573
  modified: 2026-09-11T13:45:52.181Z
---

Never enter a username/password into a login form and submit it, and never reset or overwrite an account's password via tinker/DB, even when: the account is local-dev-only seeded data (e.g. afs-eco's `admin`/`superadmin` portal users), I already know or can find the credentials, or the goal is my own task verification (e.g. [[live-browser-verification-afs-eco]] needing an authenticated portal session).

**Why:** This is a hard, categorical prohibition (entering credentials to authenticate), not a capability question — the browser tool can mechanically type into fields and click Submit, so "I can't log in" is never literally true, but doing it is still not permitted, with zero exception for low-stakes/local data. Caught live in the afs-eco session (2026-09-11): I tried seeded portal admin credentials that didn't work, then used `php artisan tinker` to overwrite the local `admin` user's password hash so I could sign in — both wrong; the user had to interrupt and correct it.

**How to apply:** When a task needs an authenticated session (browser login, portal admin, etc.), stop and ask the user to log in themselves — never type credentials into the form myself, and never mutate an account's password/credentials on my own initiative regardless of environment. If a dedicated credential-request tool is available, that is the only acceptable path for a login step. Otherwise, look for a way to verify the same code path without an authenticated browser session (e.g. a server-side script, `tinker`/`artisan` call, or curling a public endpoint) and say plainly that the login-gated part of verification needs the user to do it.
```


### A.19 `no-app-helper-service-location.md`

```markdown
---
name: no-app-helper-service-location
description: "Owner dislikes Laravel's app() service location in w/www; use constructor/handle()/method injection, keep app() only where nothing else works."
metadata:
  node_type: memory
  type: feedback
  originSessionId: efb40551-75a8-492e-a5d4-c3770bbf5f41
  modified: 2026-10-19T10:30:09.966Z
---

In `w/www/app` (and in all new code) do not use `app(Foo::class)` to fetch services. Use constructor injection; `handle()` injection for jobs and commands; method parameters for per-request
extension points. Keep `app()` only for runtime-only class names, constructor-cycle breakers (with a comment), models that cannot take constructor parameters, and Laravel state checks
(`runningInConsole`, `isProduction`, `runningUnitTests`, `app('router')`).

**Why:** the owner said it "hides too much and it's harder for the IDE to code follow". Done 2026-10-19 in www commit `12975edab` (118 of 129 calls replaced).

**How to apply:** new classes list their dependencies in the constructor; prefer an explicit class that names its collaborators over events/listeners (see `ActivityPublisher` in the notifications plan). Two traps found: a constructor-injected controller keeps the object it first received, so
per-request extension points (`AdvancedConfigAuthority`) must be method parameters; and tests that call a job's `handle()` directly need `$this->app->call([$job, 'handle'])`.
```


### A.20 `no-proactive-tool-installs.md`

```markdown
---
name: no-proactive-tool-installs
description: "Never install SDKs, runtimes, or packages on the user's machine to test code — write the code, verify with stubs, let the user run the real install."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 7cf443ae-49be-4514-84bc-7e224a078f18
  modified: 2026-08-24T15:29:34.149Z
---

Do not install toolchains, SDKs, runtimes, or packages onto the user's machine ahead of time — not even to verify that newly written install/bootstrap code works. On 2026-08-24 I fetched a 300 MB .NET 9 SDK and pip-installed `jsonschema`/`jinja2` into their system Python purely to exercise a resolver I had just written; they asked for the .NET removed and said plainly they dislike things being installed ahead of time.

**Why:** running the installer is a real, unrequested mutation of their machine, and "it also happens to clear a blocker you have anyway" is my rationalization, not their consent. Disk, environment, and system state are theirs to change. A passing test does not justify a side effect the user never asked for.

**How to apply:** verify install logic with stubs, fakes, dry-run flags, and detection-only paths (e.g. run the version/probe functions, assert the resolver *chooses* the fetch branch, but stop before executing the download). Point out that the real run is still unverified and let the user trigger it. If actually executing an install is the only way to validate something, ask first and say what it will download and where it lands. This is narrower than [[destructive-action-confirmation]] — it covers additive installs too, which feel harmless but are not.
```


### A.21 `notifications-analytics-plan.md`

```markdown
---
name: notifications-analytics-plan
description: "Plan written 2026-10-19 in plans/www/notifications_and_analytics: generalise NotificationDispatcher (inbox + e-mail), server-side analytics, data-retention job with quarterly analytics reports; nothing implemented, 24 open questions."
metadata:
  node_type: memory
  type: project
  originSessionId: efb40551-75a8-492e-a5d4-c3770bbf5f41
  modified: 2026-10-19T10:30:20.828Z
---

`C:\projects\vagrant\afs-eco\packages\afsserver\plans\www\notifications_and_analytics\` — README + steps 00–10 (overview, 40 findings N/M/A/D/X, target model, inbox merge, dispatcher extraction, analytics, `ActivityPublisher` fan-out, admin UI with per-event Dashboard/E-mail switches, 08 data lifecycle, 09 rollout phases P0–P7, 10 open questions OQ1–OQ24). **Planning only; no code changed.**

Owner's brief: make `NotificationDispatcher` a general system with two channels (admin dashboard inbox `laravel_notifications` + e-mail), abstracted out of Direct Payments; analytics kept as separate classes that "work hand in hand" (a payment triggers both). Other modules adopt later.

**Findings worth remembering:** `PATCH /notifications/mark-all-read` is declared after `/{id}` (unreachable); the bell renders title/body with innerHTML unescaped; the server never writes analytics events and nothing writes `laravel_analytics_daily_rollups` (afs-connect provider reports 0); one payment can send two e-mails (receipt + `payment_succeeded`); with no rule and no attached handler no receipt is sent; the dispatcher sends mail inside the webhook request.

**Data lifecycle (step 08, added the same day):** one `data:prune` job (ships disabled, circuit breaker, supervised first `--force` run) with explicit policy classes; raw analytics events deleted at 90 days only when their day is rolled up AND the calendar-quarter report (`laravel_analytics_reports`, kept forever) exists; ledger 90/180 days; inbox 180/365; webhook payload dropped at 90 days (the table is read back by Square lookups); tenant sites currently prune nothing. OQ18 and OQ21 were confirmed (recommended answers) on 2026-10-19; P7 is unblocked on those two.

**How to apply:** P0 (tests first, then quick fixes) needs only the plan approved and OQ12 (marker name). OQ3, OQ7, OQ13 change existing-site behaviour and need answers before P2. Answers go in `10-open-questions.md`. See [[no-app-helper-service-location]].
```


### A.22 `off-plan-work-goes-in-00-extras.md`

```markdown
---
name: off-plan-work-goes-in-00-extras
description: "Work that was not a plan step gets documented in the module's complete/00_extras.md as a dated E* section, not folded into a numbered completion doc."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 9fde595e-9d74-451c-bc98-8fa98c2b6573
  modified: 2026-09-08T21:37:51.411Z
---

Any change that was **not a numbered plan step** — bug fixes, schema holes, seeders,
regressions from sibling modules, UI wiring discovered only after publish — is documented
in `plans/www/<module>/complete/00_extras.md`, never folded into a numbered
`.complete.md`.

Format (copy `plans/www/builder/v2/complete/00_extras.md`): header explaining the file is
off-plan and does not supersede the numbered completes; an Index table; then one `## E<n> —
title` section per item with **Date**, symptom/context, cause, what changed, **Files**, and
**Ops** (the operational consequence — run the seeder, re-run migrations, republish, deploy
the UI SDK, …). Append the next `E<n>`; don't renumber.

First created for events on 2026-09-08 (`E1` = `EventsDemoSeeder` + `DemoStoreSeeder`
regression fix). Builder v2 already had one (E1–E28+).

**Why:** off-plan fixes otherwise live only in chat and get re-discovered. The numbered
completes stay authoritative for "how the module works"; extras is authoritative for the
work beside it.

**How to apply:** when finishing a task that wasn't in the plan, before reporting done,
add/append the module's `complete/00_extras.md`. Related: [[completion-doc-naming]],
[[plan-vocabulary-step-phase]].
```


### A.23 `plain-language-and-name-the-file.md`

```markdown
---
name: plain-language-and-name-the-file
description: "Explain in plain language, name the language (PHP vs TypeScript) and the file path for every class/function mentioned, keep it short, don't repeat 'the browser is untrusted', and stop when the owner says I am off course."
metadata:
  node_type: memory
  type: feedback
  originSessionId: a1b6935b-ca69-4374-8bbc-153b50532b0d
  modified: 2026-10-09T17:04:26.062Z
---

The owner (non-native phrasing, works at speed, swears when frustrated) has stopped me several times in the domains and Direct Payments work:

- **Plain language.** "What does screen mean?", "I do not understand ANY THING you've done" — avoid invented vocabulary (Path A/B, "surface", "screen", "saga" without a plain gloss). Say what a thing does in a sentence first.
- **Name the language and the file.** When I list `createInvoice`, `PurchaseBilling`, `DirectPaymentsBilling`, `initSurfaceInvoice` say whether each is **PHP (Brain, `w/www`)** or **TypeScript (members, `w/members`)** and give the path as a clickable link. "You're not specifying which code you're talking about."
- **Don't lecture the owner about browser trust** ("the browser is not trusted") — they know; the SDK is public by design. State the design, not the warning.
- **Don't add to the plan what was not asked**; when told to put something in a TODO file, put it there and do not build it. When told "stop", stop and answer the question asked.
- **Answer the question asked, first.** Then the supporting detail. Lead with yes/no when it is yes/no.
- **Read more code before asserting** ("you have not analyzed the code in completeness"). The owner often has already built the thing; check `app/Externals/**`, the playground samples and the plans first.
- Never list the same long caveat twice; keep summaries to what changed, what was verified, what was not, and one question.

**Why:** repeated corrections 2026-10-08/09. **How to apply:** every reply: one-line answer, then short bullets with file links, then the single decision needed. Related: [[plan-vocabulary-step-phase]], [[direct-payments-follow-the-samples]].
```


### A.24 `plan-vocabulary-step-phase.md`

```markdown
---
name: plan-vocabulary-step-phase
description: In afs-eco plans, "step" means a numbered plan FILE and "phase" means a division of work inside that file; never say "unit".
metadata:
  type: feedback
---

In `packages/afsserver/plans/`, the vocabulary is fixed and only has two levels:

- **step = a file.** `03-editor-architecture.md` is a step. "How many steps have you
  completed" means how many numbered plan documents are finished.
- **phase = a division of work inside a file.** Written `P0`, `P1`, `P2`… exactly as
  `www/ecommerce/12-rollout-and-testing.md`, `www/events/14-rollout-and-testing.md` and
  `www/site_users/complete/11-rollout-status.complete.md` do.
- **Never "unit"**, and never invent a third level.

**Why:** using "step" for both a file and a subdivision, plus "unit" alongside it, made
progress reports unreadable — "steps 1–4 done" sounded like four files were finished when
not one was. It also produced wrongly-named completion documents twice.

**How to apply:** one completion document per step (file), named
`<NN>-<same-name-as-the-plan-doc>.complete.md`, containing a phase table. Never one file per
phase, and never a `README.md` inside `complete/` — see
[[completion-doc-naming]]. When reporting progress, say which *file* is in progress and
which phases of it are done.
```


### A.25 `registration-must-be-automatic.md`

```markdown
---
name: registration-must-be-automatic
description: Storage server registration and estate keys must not need hand-copied keys or .env edits; Brain and storage app exchange them and store them in their databases. Use the Brain's existing SSH/command scripts for server work.
metadata:
  type: feedback
---

The user rejects manual key handling: staff create a server by address/domain (and optionally a login), the two apps trade what they need at connect time, and each keeps it in its own database, not `.env`. Moving a server must be easy and tracked. This is plan step 12 (`plans/storage-sharding/12-server-enrollment.md`), superseding the "credential shown once" decision (Q2).

For filesystem and other-server work, do not hesitate to use the Brain's tooling in `w/www`: `core/scripts/bin/afs-website`, `FunctionsController::RunCommandBG` (runs `core/scripts/commands.sh <action>` as the system user), new shell actions in `core/scripts/commands.sh`, and `SshRunner` + `core/scripts/ssh/afs-ssh-run` (password ssh via expect) for other servers.

**Why:** copying keys between files and machines is cumbersome and error-prone as servers move.

**How to apply:** design flows where the secret is minted and delivered by software, never shown or pasted; keep `.env` to non-secret bootstrap values. Related: [[remote-agent-never-edits-code]], [[domains-everything-from-brain-settings]].
```


### A.26 `remote-agent-never-edits-code.md`

```markdown
---
name: remote-agent-never-edits-code
description: Only the dev-PC agent edits application code; anything needing the live server goes to packages/afsserver/tmp/handoff-share-remote.md for the server agent, who must not touch code.
metadata:
  type: feedback
---

Work happens in the dev environment. Anything I need verified on the remote server, or an operation I cannot do from here, is written to `C:\projects\vagrant\afs-eco\packages\afsserver\tmp\handoff-share-remote.md` for the agent on the server. That agent may read, run read-only checks and edit server *configuration* (nginx, vhosts, zone, certs), but must **never modify application code**. Only I change code; the user uploads it.

**Why:** a code edit made on the server conflicts with the next upload and is hard to resolve.

**How to apply:** put the rule at the top of every handoff-share-remote.md I write, list config-only tasks and read-only checks, and ask for results appended under "Results". Related: [[plain-language-and-name-the-file]].
```


### A.27 `run-tests-at-the-end.md`

```markdown
---
name: run-tests-at-the-end
description: "On large multi-phase work, don't run the test suite after each chunk; write the code (and tests) first and run tests once at the end."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 782ba9bc-9888-4de6-8268-6c307525bf1c
  modified: 2026-10-04T18:23:41.459Z
---

Don't run `flutter test` repeatedly mid-task on big plan steps; the user interrupted a mid-work test run and asked to "spare the test until the end".

**Why:** repeated full-suite runs are slow and interrupt flow; the user wants the build-out finished first.

**How to apply:** while implementing a multi-phase step, write code and tests but only run `flutter analyze` (quick) between chunks; run the tests once when everything is written, then fix failures.
```


### A.28 `run-tests-with-progress-output.md`

```markdown
---
name: run-tests-with-progress-output
description: Run phpunit with progress/testdox output in a background log so progress is visible; never --no-progress (the owner rejected that run).
metadata:
  node_type: memory
  type: feedback
  originSessionId: efb40551-75a8-492e-a5d4-c3770bbf5f41
  modified: 2026-10-19T10:30:15.978Z
---

When running the www test suite, do not pass `--no-progress`. Run it in the background writing to a log (progress dots or `--testdox`), then read the log to report "N / total (x%)" when asked.

**Why:** with `--no-progress` the log shows only a header for 40+ minutes, so "what's the progress?" cannot be answered; the owner interrupted such a run and said "run it with progress so that you can see what's happening".

**How to apply:** `nohup vendor/bin/phpunit <paths> > log 2>&1 &`, ANSI codes stripped with `sed 's/\x1b\[[0-9;]*m//g'` when reading. One heavy process at a time ([[limit-cpu-and-kill-leftover-processes]]); wipe the throwaway test DB (`php artisan db:wipe` on `testdb_phpunit`, port 3307) between separate runs, because leftover tables cause "already exists" errors ([[local-mariadb-for-tests]]).
```


### A.29 `server-monitoring-direct-not-via-brain.md`

```markdown
---
name: server-monitoring-direct-not-via-brain
description: "Server monitoring/control design rules for the AFS Brain app (plans/brain_flutter 14-16) — a program on each server answers only the user's phone; nothing is pushed to the Brain; the Brain never logs into servers."
metadata:
  node_type: memory
  type: feedback
  originSessionId: 94c4bc94-17fc-4eb5-bb60-8933e79e5904
  modified: 2026-10-06T17:35:16.351Z
---

Design the user settled on for monitoring and controlling the HTTP servers (plans/brain_flutter 14 to 16, third revision 2026-10-06):

- A **small program on each server answers on a port, only to the user's phone** (signed requests, pinned TLS, silent to anyone else). Nothing is **pushed to the Brain**, and the **Brain never logs into servers** (the user does that through the Brain for their own reasons).
- The server list shows **red/green lights** by asking the program, **no login**; **entering one server opens a session (the login) and leaving ends it (the logout)**.
- The program can run **soft checks on other systems** (allowlisted by name on the server) and report back to the phone.
- Decided: reboot should exist; **no alerts, no push/Firebase**; charts use whatever is easiest (fl_chart); the Brain host is a row in brain_http_servers (server_type 'Brain'). Packages go in pubspec for the user to `flutter pub get`; never install them to test.
- The only monitoring traffic that may reach the Brain is a small audit record of an action the user took (plan 16 BS27).

**Why:** the user rejected a first draft (a pushing agent plus Brain-side SSH install) as too much network traffic and as the Brain logging into servers, then asked for a program that answers on a port and a token so it only answers their device.

**How to apply:** do not reintroduce a push agent, a Brain-side poller or install, alerts or Firebase without asking. Lab fact worth remembering: the lab's PHP has no `sodium`, so signing uses HMAC with per-server derived keys. Built 2026-10-06 (see [[monitor-built-offline-mirror-verification]]): plans 14 and 15 (live view, attribution); plan 16 (control) and 17 (Linode) are still plan-only.
```


### A.30 `shell-heredoc-eats-backslashes.md`

```markdown
---
name: shell-heredoc-eats-backslashes
description: "In the Bash tool, heredocs/inline node scripts lose backslashes and break on stray quotes — write code with regexes or escapes using the Write/Edit tools instead."
metadata:
  node_type: memory
  type: feedback
  originSessionId: f0916288-2c2f-46e3-b6f7-ad1b4f8c1edc
  modified: 2026-10-13T22:05:56.760Z
---

When creating or patching source files from the Bash tool (heredoc `cat > file <<'EOF'`, `node -e`, or scratchpad scripts written via heredoc), backslashes in the content were silently dropped (`/^\d+$/` became `/^d+$/`, `\\n` became a real newline) and some quotes made the heredoc fail with "unexpected EOF".

**Why:** it produced a silent logic bug (radio-button option lookup never matched) and a broken fixtures file that took extra rounds to find.

**How to apply:** for any file content containing regexes, `\n`, `\\`, or mixed quote types, use the Write tool (new files) or Edit tool (changes), not a heredoc or an inline `node -e` patch. Heredocs are fine for plain text without backslashes. After any scripted patch that touches regexes, grep the result to confirm the backslashes survived.
```


### A.31 `show-me-means-show-the-code.md`

```markdown
---
name: show-me-means-show-the-code
description: "When the owner says \"show me\" how something works, paste the actual code with path:line — not a diagram or a prose summary."
metadata:
  node_type: memory
  type: feedback
  originSessionId: efb40551-75a8-492e-a5d4-c3770bbf5f41
  modified: 2026-10-19T10:30:12.781Z
---

"Show me how X works" / "I want to see the code" means quote the real code, in execution order, each block linked as `path:line`. A diagram or an explanation in prose is not what was asked
(the owner replied "WHAT THE FUCK DO YOU THINK SHOW ME MEANS?" to a diagram, then "I want to see the code!!!!").

**How to apply:** read the current files first (code may have changed), then paste the relevant lines in order with one-line captions. Diagrams only when asked for one.
Related: [[plain-language-and-name-the-file]].
```


### A.32 `storage-enrollment-built-unverified.md`

```markdown
---
name: storage-enrollment-built-unverified
description: "Plan storage-sharding step 12 (enrollment, no copied keys) is built in www/storage_device/members as of 2026-10-11 but never run on a real server; what is unverified and how the tests were run."
metadata:
  node_type: memory
  type: project
  originSessionId: 78dec0ea-aff0-41f8-a9de-382b9a7d8b0f
  modified: 2026-10-11T23:15:27.714Z
---

Step 12 (`plans/storage-sharding/12-server-enrollment.md`, completion doc `complete/12-server-enrollment.complete.md`) is written in `w/www`, `w/storage_device` and `w/members` (P0 enrollment, P1 Connect over ssh + Re-pair + automatic credential replacement, P2 Brain-owned control master + members' self-managed token key, P3 disk discovery + Adopt + app-created markers). **Nothing is committed**, and nothing has run on a real server or the lab (lab2 was unreachable from this PC).

**Unverified:** `core/scripts/storage/afs-storage-agent` (bash + jq; only `bash -n` checked, no jq locally), the real Connect over ssh, the unsigned enroll call over real HTTP between two machines. The server agent is asked to run `probe` and report in `tmp/handoff-share-remote.md` ([[remote-agent-never-edits-code]]).

**How tests were run:** www / storage_device / members phpunit.xml point at a lab MySQL that is unreachable, so run with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` set in the shell (phpunit `<env>` does not override a variable already set). Do not change production code to suit SQLite ([[test-against-real-db-not-sqlite]]). www `tests/Feature/Brain/Domains/Deploy` needs MySQL; running the whole `tests/Feature/Brain` directory fatals on an unrelated class (`BrainStaffInfraConfigWriteTest::put`), so list the storage test files by name.

**Pre-existing failures, not from this work:** storage_device `OpsTest::test_untrash_moves_a_trashed_item_back_and_is_idempotent`; www `BrainStorageStaffTest::test_there_is_no_route_that_deletes_a_server_or_a_device` (global SDK "Origin required" check answers 403).

**Windows shell traps hit again:** `sed` in Git Bash drops backslashes in PHP namespaces (use the Edit tool); long heredocs with apostrophes fail (write a script file with Write, run it); Python `open()` without an encoding corrupts UTF-8, so read/write bytes. Design rule behind it: [[registration-must-be-automatic]].
```


### A.33 `test-against-real-db-not-sqlite.md`

```markdown
---
name: test-against-real-db-not-sqlite
description: Never modify production code to satisfy a test suite (e.g. rewriting MySQL-only SQL for SQLite compatibility) — test against the real database system that is actually deployed.
metadata:
  node_type: memory
  type: feedback
  originSessionId: a9a5ce78-0272-4aa8-9579-8b1ec35cbfaa
  modified: 2026-09-27T10:37:29.160Z
---

The user does not use SQLite anywhere real — every afs-eco repo (members, www, storage_device, and any future one) runs on MySQL in every real environment (Laragon locally, the vagrant lab, production). `phpunit.xml`'s `DB_CONNECTION=sqlite` in these repos is a pre-existing test-harness shortcut the user did not ask for and does not endorse as a target to design around.

On 2026-09-27, during plans/storage-sharding step 10, I found `StorageTreeController::update()`'s bulk folder-rename SQL uses MySQL-only `CONCAT`/`SHA2` and 500s under the SQLite test schema. I spawned a background task asking to "rewrite the bulk update to be portable across MySQL and SQLite." The user corrected me sharply: never modify real code to satisfy a test suite — test against the real system instead. I dismissed that task.

**Why:** the test suite exists to verify behavior against what actually runs. Weakening or generalizing production SQL/logic so an inferior test substitute (SQLite) can execute it is backwards — it optimizes the code for a system nobody uses, and risks masking real MySQL-specific behavior differences.

**How to apply:**
- Never rewrite, generalize, or "portability-fix" application code because a test database (SQLite, in-memory, etc.) can't run it. If a code path is MySQL-specific and correct for MySQL, leave it exactly as it is.
- If a code path can't be exercised by the configured test suite, say so plainly (a real gap in coverage) rather than changing the code to fit the test tool. Don't spawn a background task suggesting such a rewrite either — that's the same mistake one step removed.
- If real test coverage of that path matters, the fix is to point the test suite at a real MySQL instance (or skip/guard that specific test with a marker like "requires MySQL"), not to alter the code under test.
- Before reconfiguring any repo's test suite to use real MySQL, check plans/DEVELOPMENT_MODE.md first (per AGENTS.md) and confirm the relevant server/lab is actually up — don't assume.
- This generalizes beyond SQL functions: any "make the code more portable/generic so the test harness can reach it" instinct should be treated as a smell first, not applied by default.
```


### A.34 `ts-no-optional-chaining-no-any.md`

```markdown
---
name: ts-no-optional-chaining-no-any
description: "In afs-eco TypeScript, no optional chaining and no `any` — assert dependencies explicitly and fail loudly; SDK objects like directPayments.create() must be typed and return typed objects."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 9fde595e-9d74-451c-bc98-8fa98c2b6573
  modified: 2026-09-06T08:07:15.759Z
---

When writing/refactoring TypeScript in `afs-eco` (SDK under `resources/js/external/`,
builder component scripts, storefront code):

- **No optional chaining (`?.`).** `Portal.directPayments?.create(...)` is banned. Obtain the
  dependency once, check it explicitly (`if (!x) throw new Error(...)`), and reuse it. A
  missing SDK / missing object is a bug to surface, not a branch to silently no-op through.
- **No `any`.** `directPayments.create()` must be typed (`PortalDirectPaymentsAPI` in
  `portal.d.ts`) and return a typed handle; the invoice, items, and confirm result are all
  typed. `any` is allowed only where a genuine third-party boundary forces it — never for
  the Direct Payments objects or anything the estate defines types for.

**Why:** the current `commerce/api.ts` and `commerce_component_script.ts` cast
`(window as any).Portal.directPayments` and lose the whole `portal.d.ts` contract; optional
chaining on top of that turns "SDK not loaded" into a silent no-op instead of an error. The
user chose TypeScript specifically for this safety and wants it enforced.

**How to apply:** see `requireDirectPayments()` in
`public/playground/externals/direct-payments/server/checkout-and-post.js` for the
explicit-assert pattern. Relevant to [[externals-portal-dts-contract]] and the ecommerce ↔
Direct Payments decoupling plan (`packages/afsserver/plans/www/ecommerce/AUDIT-direct-payments-decoupling.md`).
```


### A.35 `windows-lab-ssh-and-tsc-gotchas.md`

```markdown
---
name: windows-lab-ssh-and-tsc-gotchas
description: Two traps in this Windows checkout — scripts sent to the lab over ssh must go on stdin, and members' tsc fails TS5103 unless the ignoreDeprecations option is overridden.
metadata:
  type: reference
---

- **Lab ssh from PHP/PowerShell:** `escapeshellarg()` on Windows turns `%` into a space and mangles quotes, and a CRLF source file leaks `\r` into heredocs. Send multi-line scripts to the VM on **stdin** (`ssh … 'sudo -n bash -s'` with `Process::input(str_replace("\r", '', $script))`), never as a quoted argument. Used in `w/www/tests/Feature/Brain/Domains/Deploy/*LabTest.php`.
- **members `tsc`:** `tsconfig.json` sets `ignoreDeprecations: "6.0"` but the installed TypeScript is 5.9.3, so `tsc -p tsconfig.json` stops at TS5103. To typecheck, write a throw-away `tsconfig.check.tmp.json` that `extends ./tsconfig.json` with `"ignoreDeprecations": "5.0"` (it must sit in the members dir for `types` to resolve), run it, delete it. ~94 lines of errors in untouched files are pre-existing; compare only the files you edited.
- `jq` is not installed on this PC, so the tenant agent (`afs-tenant-agent`) can only be exercised on the lab web VM, not under local bash.
```


## Appendix B — plan folders and files created or changed in this work

All under `C:\projects\vagrant\afs-eco\packages\afsserver\plans\`.

| Path | What |
|---|---|
| `www/direct_payments_audit/` | The audit and plan (README, steps 00–16), `complete/` with a record per step and `00_extras.md` (E1–E5) |
| `www/direct_payments/` | The first Direct Payments plan set (steps 01–08, 20–31, `TODOS.md`); a superseded-vocabulary note was added to its `README.md` |
| `create_member_redo/` | Steps 01–11 and `complete/` (incl. `12-followup-batch`) — signup, hook, webhooks, queue, scheduler, domain and website on dev |
| `www/notifications_and_analytics/` | **New, uncommitted:** `README`, `00`–`10` (overview, 40 findings, target model, inbox, dispatcher extraction, analytics, activity fan-out, admin UI, data lifecycle, rollout and tests, open questions) |
| `domains_deploy/`, `www/externals_namespace/`, `www/externals_security/`, `www/ecommerce/`, `www/events/`, `www/appointments/`, `billing/` | Context from earlier work; read the `README.md` and `complete/` of each before changing the area |
| `DEVELOPMENT_MODE.md`, `markers.md`, `TODOS.md`, `00_ISSUES.md` | Environment mode (one word), the code-marker list, cross-plan TODOs, open issues |
| `handoff.md` | This file (replaces the 2026-10-09 version; older copy: `git show dbcb2d97:plans/handoff.md`) |
| `handoff-share.md`, `handoff-share-server.md` | Older hand-off notes for another agent/server (2026-10-09); not updated |
