# 04 — The lab and the template (P0)

Fixes **OF2, OF3, OF4, OF5, OF6, OF7, OF8**. Mode is `vagrant` (`plans/DEVELOPMENT_MODE.md`); the lab is private and disposable (its security is not a concern). Anything that **changes** the lab — provisioning, installing node, VM memory, the lab database — waits for the owner's say-so, and nothing here switches an `.env` automatically (standing rule).

## 4.1 Prerequisites on the lab

| # | Need | Found (read-only, 2026-10-09) | What to do |
|---|---|---|---|
| L1 | Web VM can build: `node`/`npm` for root over non-interactive ssh | missing | the "Tenant host dependency access" block of `lab2/scripts/initial_install.sh` (Node/npm/npx links, `~/.npmrc` — already present, 178 bytes — and Composer's GitHub token; the Vagrantfile passes `AFS_NPM_TOKEN`, `AFS_GITHUB_TOKEN`) |
| L2 | Web VM memory for the build | 1.7 GB, no swap | 3–4 GB RAM or a swap file (Vagrantfile) — see also §4.3 |
| L3 | Web VM MariaDB root password equals the one in the job (`brain.mysql_password`, from the Brain config) | not checked | read-only check on the first run |
| L4 | Brain VM runs the current `w/www` and `w/members` | older code: no newest migration, no scheduler/worker | `vagrant provision brain --provision-with app-www` and `app-members`; `php artisan migrate --force`. The scheduler/worker is not needed by the old flow itself |
| L5 | The script folder the pipeline runs from (OF2) | lab `SUBDOMAIN_PROPERTIES_SUBDOMAIN="www15"` → `dev/members15` (missing) | decide the value with OD3; the lab's Brain has `subdomain_properties` rows for both `www` and `www15`; `SUBPATH` and the folder must agree |
| L6 | The wrapper `core/scripts/bin/afs-website` (OF3) | plain `-rw-r--r--` file | OD5: compile it root-owned and setuid on a local filesystem, or replace it; first confirm on the first run whether the members tree is a shared folder |
| L7 | Checkpoint accepts the tenant's origin (OF8) | lab auth lists only the apps' hosts, empty suffixes | `CHECKPOINT_SSO_ALLOWED_DOMAIN_SUFFIXES=.sites.test2,.10-0-0-243.sslip.io` in `w/auth/_env/lab2/.env`; the owner redeploys auth |
| L8 | The template in Gitea is current (OF5) | `019e2a56ab`, two days behind | §4.2 |
| L9 | A test domain whose names the lab DNS answers with the **web** VM | the three lab test names (`tenant1…3.10-0-0-243.sslip.io`) point at the Brain VM | a fourth name pointing at `10.0.0.244`, through the existing `lab2/dns` tooling (`add-zone.sh`, `lab-domains.list`) |

## 4.2 The template (P0)

| Task | Detail |
|---|---|
| Re-copy `w/www` → `wwwafsportal_v2` | `copy_portal_source.sh` has `wwwafsportal_v1` as its **default target** (OF6). Change the default to `wwwafsportal_v2`, or always pass both paths. Never run it against v1. |
| Carry the lock files | add `composer.lock` and `package-lock.json` to the script's list. With them `composer install` is repeatable and `npm ci` becomes possible (`npm ci` versus `npm install`: OD6). Until the owner decides, `app_install.sh` keeps `npm install`. |
| A neutral committed `.env` | the template's `.env` is `_env/www/.env` (development hosts). Copy one without `*.appfactory.studio` hosts or secrets; the real values are applied at build time (§4.3). |
| A public health route | add `GET /health` (200, the template's commit, no auth, no database detail) so the script's last check (step 03 §3.2) and the acceptance run have a target. |
| A build script that fits a small host | add `build:tenant` = `check:modules && vite build` (no `tsc --noEmit`; the type check belongs to publishing the template). `build-obsolete` is already plain `vite build`. `app_install.sh` runs `npm run build:tenant` when the script exists, else `npm run build`. |
| Commit and push | the owner commits in `wwwafsportal_v2` and pushes to Gitea `afsportal_v1` (commit only when told). The new commit is written in the completion document. |

After the copy, check on this PC without installing anything: `php -l` on changed files, `php artisan route:list` (no unresolved class), `npm run check:modules`. A real `composer install` / `npm` / build happens only on the lab host.

## 4.3 Environment values for the tenant (P0, **OF7**)

`app_install.sh` today writes `DB_*`, `MAIN_DB_*`, `VITE_SUBDOMAIN`, `APP_URL`, `ASSET_URL` and a hard-coded `BRAIN_API_URL`, after `npm run build`; the template's Checkpoint and SSO values stay at development.

Change, keeping everything else:

1. A Brain setting **`controller.tenant_env`** (a map, in the Brain config document the old flow already reads, edited in the Brain settings editor; add it to `brain_controller_schema.ts`). Lab: `CHECKPOINT_BASE_URL`, `CHECKPOINT_SSO_DOMAIN`, `CHECKPOINT_DOMAIN`, `SSO_DOMAIN*`, `BRAIN_API_URL`, `MEMBERS_URL` set to the lab hosts. Production: its own hosts, or left empty.
2. `getTenantData()` copies the map into the job as `tenant_env` (the Brain data is already in hand; no new call).
3. `app_install.sh` applies `tenant_env` with the existing `set_env_var` to **both** `www` and `www-own`, **right after the database values and before `npm run build`**. The `VITE_CHECKPOINT_*` entries in the template reference the base keys (`"${CHECKPOINT_BASE_URL}"`), so setting the base keys is enough; the first lab build verifies it (acceptance A7).
4. The hard-coded `BRAIN_API_URL` branches (`:699–710`) stay as the **fallback when `tenant_env` has no `BRAIN_API_URL`**, so production behaves exactly as today until its map is filled.
5. `APP_URL` / `ASSET_URL` per copy stay as they are.

## 4.4 `www` and `www-own` stay

`www` — the member's domain (`APP_URL` = `https://<Subdomain>.<member domain>`); `www-own` — the sites name (`APP_URL` = `ASSET_URL` = `https://<ref>.sites.<zone>`). One build in `www`, then `www` is copied to `www-own` and its values set. Not changed. To look at (OD4): `Subdomain` is the Brain's own (`www15` in the lab) and `APP_URL` for `www` is built from it (`app_install.sh:706`), so in the lab the member's address would read `https://www15.<member domain>`. The lab fixes this by its choice of `SUBDOMAIN_PROPERTIES_SUBDOMAIN` (L5), not by code.

## 4.5 Checkpoint and the tenant's browser

Sign-in from a tenant's host needs Checkpoint to allow that origin (L7). In production the sites names fit one suffix (`.sites.appfactory.studio`); a member's own domain does not fit any list kept in an `.env`. Until Checkpoint can learn a tenant's custom host, production runs Checkpoint with `CHECKPOINT_CORS_MODE=report` and reads `checkpoint.cors.would_refuse`, as the externals-security rollout already says. That is a dependency of production, not part of this plan (step 07 lists it as a question).

## 4.6 The acceptance run (one real deploy on the lab)

In order; each is pass or fail with the evidence in the completion document.

| # | Check |
|---|---|
| A1 | Brain: the two routes of step 02 answer a signed request, refuse an unsigned one; members falls back to the old calls only when the route is absent |
| A2 | Press *Create website* for the test domain: the response holds only `tracker_key` and `website_reference` (no secret anywhere in it); the bar reaches 100% |
| A3 | The job file was mode `0600` and is gone afterwards |
| A4 | On the web VM: Linux user, database and user, `/home/<u>/websites/www` and `www-own`, both vhost files, `nginx -t` and `apachectl -t` pass |
| A5 | `subdomain_properties` has 21 rows for the tenant, none empty; `reference_num` equals the Brain's tenant record's; `db_password` opens the tenant's database |
| A6 | Both copies' `.env`: Checkpoint, SSO and Brain URLs are the lab's; DB values are the tenant's; `APP_URL`/`ASSET_URL` differ per copy |
| A7 | `public/build/manifest.json` exists; an asset URL answers 200 **from each name** (`curl --resolve`), and the page in a browser loads its scripts and styles from the host it was opened on |
| A8 | `/health` answers 200 for both names through nginx `:80` → Apache `:808` |
| A9 | The sites name resolves to the web VM on the lab DNS; the sites name answers over HTTPS with the lab certificate; no certbot ran (`LETSENCRYPT_CHECK=false`, in the log) |
| A10 | The tenant's own Gitea repository exists |
| A11 | Sign-in from the tenant's host through lab Checkpoint works in a browser — **the owner signs in** (the agent never types credentials); no `CHECKPOINT_E_ORIGIN` |
| A12 | Failure injection: stop the build at `npm run build` → progress `failed` with the reason, the website row marked failed; run the build again → completes without a hand cleanup |
| A13 | Remove the acceptance tenant with the existing scripts (user, database, vhosts, repo, DNS record, rows), and list leftovers with a read-only script |

Not provable on the lab: any Let's Encrypt issuance, the production wildcard, production hosts' memory and ssh versions.

## 4.7 Work

| Phase | Work |
|---|---|
| **P0** | L1–L9 (each with the owner's go-ahead), §4.2, §4.3, then A1–A13 |
| **P1** | The acceptance run as a repeatable script for A4–A9 (ssh + curl); a read-only `lab2/scripts/old-build-check.sh` that prints L1–L3, L6 and leftovers |
