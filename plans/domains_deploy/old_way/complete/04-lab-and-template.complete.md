# 04-lab-and-template — completion record

**Plan file:** [`../04-lab-and-template.md`](../04-lab-and-template.md)
**Repos:** `w/www`, `w/members`, `packages/afsserver`
**Date:** 2026-10-22
**Status:** 🟡 The code parts of §4.2 are done and tested; §4.3 (`tenant_env`) was built and then removed on the owner's instruction (see §4.3). §4.1 (lab prerequisites L1–L9) and §4.6 (the acceptance run) are **not done**: they change or exercise a real server and need your word. The target in use is **dev** (plan `create_member_redo/11`), not the Vagrant lab, so §4.1 was replaced by read-only checks of dev. Nothing is deployed or committed.

## §4.1 Prerequisites — lab not touched; dev checked read-only (2026-10-22, `remote-run.sh`)

| Plan | Dev (`45.79.42.137`, the one server: members, Brain, DNS and the only `Head` tenant host) |
|---|---|
| L1 tools | node v22.13.0, npm 10.9.2, composer, git, jq, curl, mysql, expect, php, **g++, certbot, nginx, apachectl — all present** |
| L2 memory/disk | 7.6 GB RAM, 8.7 GB swap, 4 CPUs, 37 GB free on `/` (the build checks 5 GB) |
| L3 MySQL root password = the Brain config's | not checked (needs the secret); the build's preflight tests the login and fails clearly |
| L5 script folder | settled by plan 11: `base_path()`; dev `SUBDOMAIN_PROPERTIES_SUBDOMAIN="www15"`, `VITE_SUBDOMAIN="www15"` |
| L6 wrapper `bin/afs-website` | `apache:apache 755`, **not setuid** (known; the owner is fixing it) |
| L4/L7/L8/L9 | lab-only (Brain VM provisioning, lab Checkpoint suffixes, lab DNS names): **not touched** |
| `hostname -I` | lists `45.79.42.137`, so the same-machine detection added in step 03 (`AFS_JOB_LOCAL`) works here |

Also found: `appfactory.studio.include.zone` begins `$ORIGIN sites.appfactory.studio.`, so the line `build_dns` appends (`<user> IN A <ip>`) **does** resolve as `<user>.sites.appfactory.studio`. (My first audit doubted this; it was wrong for the sites name. A DNS question remains for the *member's own* domain — see step 07.) The append has no serial bump, which matters only for secondary name servers.

## §4.2 The template

| Task | State |
|---|---|
| `copy_portal_source.sh` default target | **Reverted, no change.** The plan says the default should be `wwwafsportal_v2` and refuse v1; the owner says the current template repo is `wwwafsportal_v1` and `wwwafsportal_v2` is not to be worried about. The default stays as it was. |
| Carry the lock files | **Done.** `composer.lock` and `package-lock.json` added to the copy list. `app_install.sh` still uses `npm install` (OD6 unchanged until you decide). |
| `GET /health` | **Done.** `w/www/routes/web.php`: `200 {"ok":true}`, no login, nothing about the database. The build's last check still asks the front page (it works with every template, an older one has no `/health`). |
| `build:tenant` | **Done.** `w/www/package.json`: `npm run check:modules && vite build`. `app_install.sh` runs it when the template has it, otherwise `build`; the log names which. |
| Re-copy `w/www` into the template repo, commit, push to Gitea `afsportal_v1` | **Not done, owner's step.** The owner works with `wwwafsportal_v1` (which is out of scope for this agent: never read or edited), so the re-copy, commit and push are theirs. What the template needs from `w/www` is in `w/www` already: `/health`, `build:tenant`, the lock files. |
| Neutral committed `.env` | **Not done, and not needed.** The template's `.env` carries the environment's values; the owner fills them in. Only the values that differ per tenant are set by the build (database, `APP_URL`, `ASSET_URL`, `VITE_SUBDOMAIN`, `BRAIN_API_URL` from the Brain's subdomain properties). |

## §4.3 Environment values for the tenant — REMOVED (owner, 2026-10-23)

The plan proposed a Brain setting `controller.tenant_env` that overrode the template's `.env` values at build time. It was built on 2026-10-22 and **removed on 2026-10-23 on the owner's instruction**: the variable values come with the `.env` file and the owner fills them in; the build sets only the dynamic ones. Removed: the settings-editor entry, `DomainController::tenantEnv()` and the job's `tenant_env`, `apply_tenant_env` in `app_install.sh`, and the test. `BRAIN_API_URL` is again `brain.api_url` (from the Brain's subdomain properties) with the old literals as the fallback, as before this step. Plan §4.3 and the plan's finding OF7 are therefore **not** to be built.

## §4.4 and §4.5

`www` / `www-own` unchanged. Checkpoint accepting the tenant's origin (L7, §4.5) is a dependency of sign-in, not changed.

## How it was checked

* `w/members`: `DomainBrainSurfaceTest` — 25 tests, 109 assertions, OK (after `tenant_env` was removed).
* `w/www`: new `TenantHealthRouteTest` — 2 tests, 6 assertions, OK (`/health` 200 and nothing else; `build:tenant` exists, runs the module check, no `tsc`). Local MariaDB, stopped afterwards.
* (The `apply_tenant_env` harness run on the dev server on 2026-10-22 belonged to the removed `tenant_env`.)
* `bash -n` on `app_install.sh` and `copy_portal_source.sh` (the latter changed only by adding the two lock files to its copy list).

## §4.6 Acceptance run — not run

Needs a real *Create website*. On dev it also changes real DNS and issues a real certificate (`LETSENCRYPT_CHECK=true`). Status of each check:

| # | State |
|---|---|
| A1 signed routes | **Passed live** (step 02: the dev members app got HTTP 200 and the data from the Brain). Unsigned refusal: tested. |
| A2–A13 | Not run. A2 (response holds only `tracker_key`, `website_reference`), A3 (job file 0600/gone), A12 (failure injection) now have code behind them; A9 on dev means certbot runs for the sites name. A13 (removal of an acceptance tenant) has **no script** — the build leaves its user, database, Git repository and directories behind on failure. |

## Not done

* §4.7 P1: the repeatable acceptance script and a read-only `old-build-check.sh` — not written (they belong to the first real run).
* A GitHub personal access token is written in a comment in `brain_controller_schema.ts` (line near `appfactory_github`): it should be revoked and the comment removed. I did not touch it.
