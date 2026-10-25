# Handoff — cloud-storage consistency audit and lab2 changes (2026-10-02)

For the computer that deploys the web project files to the lab2 servers. Every computer has the same project files; this
describes what was changed on the **PC** (`DESKTOP-SOL0T76`, `C:\projects\vagrant`) so you can pick it up and deploy.

## 10. Addendum 2026-10-04 — AFS Brain phone app (`afsbrain_flutter`): server pieces to deploy to lab2

**What this is.** A new personal Android app, `afsbrain_flutter`, that manages members from the phone as the Brain member `appfactorystudio`, with no
login screen. Plan: `packages/afsserver/plans/brain_flutter/` (read `README.md`, then `02` and `04`). It needs three things in `w/www` that did **not exist** on lab2 before
today: a command that mints a long-lived device token, a `whoami` endpoint the app calls at startup, and one config key. They are written and committed on the PC; **nothing
is deployed and the PHPUnit tests have not been run** (the test database `192.168.56.11` is unreachable from the PC, and lab2's database was not written to).

Probed from the PC before the change: `https://www.10-0-0-243.sslip.io/portal/api/v1/brain/staff/whoami` answered **404**; `/portal/api/auth/me` answered 401 `AUTH_MISSING`
(correct). So the lab is up and `www` is reachable.

### What changed (all committed; nothing here changes a live `.env`)

| Where | Change |
|---|---|
| `w/www` (repo `www`, branch `main`) | **BS1** `app/Console/Commands/BrainDeviceToken.php` (`php artisan brain:device-token`), `app/Models/BrainDeviceToken.php`, migration `database/migrations/2026_10_04_000001_create_brain_device_tokens_table.php` (new table `brain_device_tokens`, a label registry only). **BS2** `app/Http/Controllers/Brain/Staff/StaffWhoamiController.php`, `app/Services/Brain/Staff/StaffCapabilities.php`, a new route group `api/v1/brain/staff` in `routes/portal.php` (`brain.only` + `portal.admin.auth` + `portal.level99`). **BS12** `config/brain.php` gains `membership_direct_payment` (reads `BRAIN_MEMBERSHIP_DIRECT_PAYMENT`, falls back to `VITE_DIRECT_PAYMENT_ID`, which already holds `vfPSdk2xj` in the lab env, so no env edit is needed). Tests: `tests/Feature/Brain/BrainDeviceTokenCommandTest.php`, `BrainStaffWhoamiTest.php`, `tests/Concerns/CreatesBrainDeviceTokenTables.php`. |
| `packages/afsserver` | `bin/use-env.sh`, `bin/use-env.ps1`: `afsbrain_flutter` added to the client apps they switch. `bin/check-env/check-env.py`: `afsbrain_flutter` added to `CLIENTS` (`PORTAL_API_URL`→www, `REGISTRY_URL`→auth). `plans/markers.md`: new marker. `plans/brain_flutter/**`: plan docs and `complete/02-…`, `complete/03-…`. |
| `afsbrain_flutter` (new folder beside `afsmobile_flutter`) | The Flutter project. It was **not a git repo**; the PC ran `git init` in it and made one local commit (no remote). Copy the folder to your computer if you need it there; the three `_env/<mode>/.env` files and the root `.env` are **gitignored** (they carry the token) and will not travel with git. |

The migration is additive (one new table, guarded with `hasTable`). No existing table, route or middleware changed. The only edit to an existing route file is the new group.

### What you need to do (the computer that hosts the lab2 VMs)

1. **Get the files.** Pull `www` (`git pull` in `w/www`) and `afs-eco/packages/afsserver`, or `Lab.ps1 sync <computer> -Estate`. Live `.env` files are never copied.
2. **Do not run a bare `php artisan migrate` blindly.** It applies *every* pending migration in `www` to the lab database. First look: `php artisan migrate:status` (as the app user, in the `www` app root on brain). If `2026_10_04_000001_create_brain_device_tokens_table` is the only pending one, run `php artisan migrate`. If others are pending, tell the PC session before running, or run just this one: `php artisan migrate --path=database/migrations/2026_10_04_000001_create_brain_device_tokens_table.php`.
3. **Deploy `www`:** `vagrant provision brain --provision-with app-www`; then `php artisan config:clear` and `php artisan route:clear` in the `www` root. Never `config:cache`.
4. **Verify the endpoint exists** (no token needed for this check): `curl -sk -o /dev/null -w "%{http_code}\n" https://www.10-0-0-243.sslip.io/portal/api/v1/brain/staff/whoami` must print **401** (it printed 404 before). 404 = the route is not loaded yet (config/route cache or not deployed).
5. **Mint the phone's token — a person does this, not a script.** On brain, in the `www` root as the app user:
   ```bash
   php artisan brain:device-token --label="moto-g-play-2024"
   ```
   It prints one line, once: `BRAIN_DEVICE_TOKEN=<64 characters>`, plus an id. **Do not paste it into chat, a commit, a log or this file.** Hand it to the person at the PC out of band; they put it in `afsbrain_flutter/_env/lab2/.env` and `.env`. `php artisan brain:device-token --list` shows tokens (never the value); `--revoke=<id>` or `--revoke-all` kills one or all.
6. **Run the PHPUnit files** where a test database is reachable (they create their tables only if absent, never drop one, and delete the `bft_`/`bfw_` rows they add):
   ```bash
   php artisan test --filter=BrainDeviceTokenCommandTest
   php artisan test --filter=BrainStaffWhoamiTest
   ```
   Report any failure to the PC session; these tests have never been executed.
7. **`use-env`:** with `afsbrain_flutter` now in `CLIENT_APPS`, `use-env.sh <mode>` also copies `afsbrain_flutter/_env/<mode>/.env` over its `.env`. On a computer where that folder does not exist it is skipped with a note (not an error). Still run by hand, with `--skip-sync`.

### Phone side (whoever holds the phone; same as section 7 prerequisites)
Lab CA installed on the phone, phone on the `10.0.0.x` Wi-Fi, **debug** build (`cd afsbrain_flutter && flutter run`). With a valid token the app shows the connected screen headed `appfactorystudio` and an amber `LAB2` badge; the Members list is the next phase (P1) and does not exist yet. The failure screens say exactly what is wrong: "Not paired" (empty token), "Token rejected/expired", "Not the Brain member" (token valid but not level 99), "Wrong identity", "Can't reach the server", "Certificate rejected".

### State of the PC checks
`flutter analyze` clean, `flutter test` 49 pass, `tool/gates.sh` green (no hard-coded hosts, env parity via `check-env.py`, gitignore). PHP lint clean; `php artisan list` shows the command; route list shows the `whoami` route with the right middleware; a no-database smoke test of `StaffCapabilities` returned `allocations/overrides/money_ledger/afs_connect/apps/storage = true` and the unbuilt ones (`members`, `member_create`, `billing_overview`, `subscription_staff`, `subscription_pricing`, `account_close`) `= false`, which is correct until BS3–BS11 land.



### Device token
BRAIN_DEVICE_TOKEN=yOekc7y01kiwJQDiKqj0EYmH315TO2SOAeO410YKuaNE7VyaGvaJLAP7ctdUirkc
id: 1   label: moto-g-play-2024   expires: never
Paste the BRAIN_DEVICE_TOKEN line into afsbrain_flutter/_env/<mode>/.env, switch to that mode and rebuild.



## 11. Addendum 2026-10-04 (later) — AFS Brain app P1: two more `www` routes to deploy (no migration)

P1 (member list and detail) needs two read-only server changes in `w/www`, **uncommitted on the PC until it is told to commit** (ask the PC session to commit and push first, then pull):

| Where | Change |
|---|---|
| `w/www/app/Http/Controllers/Portal/Admin/MembershipController.php` | **BS3.** `GET /portal/api/v1/memberships` gains optional `status`, `tier`, `billing_state`, `sort`/`dir` and `summary=1`. With none of them the query, order and response are unchanged (the browser SPA is unaffected). |
| `w/www/routes/portal.php`, `app/Http/Controllers/Brain/Staff/StaffMemberController.php` | **BS4.** `GET /portal/api/v1/brain/staff/members/{ref}` (inside the existing `brain/staff` group: `brain.only` + `portal.admin.auth` + `portal.level99`). Read-only. |
| `app/Services/Brain/Staff/StaffCapabilities.php` | `whoami` now reports `members: true` (both routes exist). `member_profile_edit` stays `false` until BS5. |
| `tests/Feature/Brain/BrainStaffMembersTest.php`, `tests/Concerns/CreatesBrainDeviceTokenTables.php` | New tests (BS3 filters, BS4 shape/404/level 99) and the extra tables they need (created only if absent, never dropped). **Not yet run against a database.** |

**Lab computer:** after pulling, `vagrant provision brain --provision-with app-www`, then `php artisan route:clear` and `config:clear` in the `www` root. No migration. Verify with no token: `GET .../brain/staff/members/x` must answer **401** (404 = not deployed or route cache stale). Then run `php artisan test --filter=BrainStaffMembersTest` and `--filter=BrainStaffWhoamiTest` where the test database is reachable and report any failure.

**Phone:** `cd afsbrain_flutter && flutter run` (lab CA installed, same Wi-Fi). Expect the Members tab: all lab members, search, filter and sort, pull-to-refresh, tap for detail with Details and Activity. The app already holds the lab device token (`_env/lab2/.env` and `.env`, both gitignored; they do not travel with git).

PC state: `flutter analyze` clean, `flutter test` 94 pass, `tool/gates.sh` green. The test fixtures are hand-written from the server code, not captured; see `afsbrain_flutter/test/fixtures/README.md` for the capture commands.

## 12. Addendum 2026-10-04 (night) — AFS Brain app P2: member writes (BS5, BS7, BS10) — deploy with section 11

Same deploy as section 11 (no migration, nothing new in the database): after the lab computer pulls `w/www`, `vagrant provision brain --provision-with app-www`, then `php artisan route:clear` and `config:clear` in the `www` root. **Uncommitted on the PC until it is told to commit** (ask the PC session to commit and push first).

| Where (`w/www`) | Change |
|---|---|
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | **BS5** `PUT /portal/api/v1/memberships/{ref}` now also accepts `street, city, state, zip_code, country_code, country_name` (length-checked, 422 when over), and writes a `profile_updated` event holding each changed field's before/after (nothing is written when nothing changed). Email, username, level, plan, status and payment references are still **not** writable there. **BS10** `PUT .../{ref}`, `/status` and `/tier` write `source = admin_mobile` plus the device label when the caller's token is a registered, unrevoked device token; browser callers are audited `admin` exactly as before. Self-protection: a device-token caller changing the **acting member** gets `422 BS_E_SELF_ACTION` (browsers are unaffected). |
| `app/Services/Brain/Staff/DeviceCaller.php` (new), `app/Models/BrainMembershipEvent.php` | The device lookup; `EVENT_PROFILE_UPDATED`. |
| `app/Http/Controllers/Brain/Staff/StaffCreateOptionsController.php` (new), `routes/portal.php` | **BS7** `GET /portal/api/v1/brain/staff/create-options`: plans (from the controller's `TIER_LABELS`), account statuses, the Brain Direct Payment, and **active** processors by `reference_num`, `provider`, `name` only (never keys or mode). |
| `app/Services/Brain/Staff/StaffCapabilities.php` | `whoami` now reports `member_create: true` and `member_profile_edit: true`. |
| `tests/Feature/Brain/BrainStaffWriteTest.php` (new), `BrainStaffWhoamiTest.php`, `tests/Concerns/CreatesBrainDeviceTokenTables.php` | New and updated tests. **Not yet run against a database.** |

**Verify after deploying** (no token needed): `GET .../brain/staff/create-options` must answer **401** (404 = not deployed or stale route cache). Then, where the test database is reachable: `php artisan test --filter=BrainStaffWriteTest`, `--filter=BrainStaffMembersTest`, `--filter=BrainStaffWhoamiTest`; report any failure to the PC session.

**One behaviour change to know about:** the browser Brain console's member **profile save** (`PUT /memberships/{ref}`) is now validated for field length and may also carry address fields. The browser only sends names, so it behaves as before.

**Phone:** `cd afsbrain_flutter && flutter run`. With the deploy done and the app reopened: Members tab with an **Add** button; a member's menu (top right) offers **Edit profile**, **Change plan**, **Change status**; the signed-in member (`appfactorystudio`) shows no menu. Marking for deletion on a production build asks you to type the username. **Do not test destructive actions on real members**; create a throwaway member from the app first.

PC state: `flutter analyze` clean, `flutter test` 171 pass, `tool/gates.sh` green. The stale-record **Review changes** diff (06 §6.3.1) is not built: a conflict offers Reload (discarding the draft) only.

## 13. Addendum 2026-10-04 (late) — AFS Brain app, file 04 finished: BS6 / BS8 / BS9 (subscriptions, payments, billing overview)

**This one touches a money-moving controller. Run the regression BEFORE deploying.** On the lab computer, in `w/www`, with a reachable test database:

```bash
php artisan test tests/Feature/DirectPayments        # the self-service subscription tests: MUST be green
php artisan test --filter=BrainStaff                 # all the new staff tests
php artisan test --filter=BrainDeviceToken
```
Report any failure to the PC session; **none of these has ever been executed** (the PC has no reachable MySQL). The deploy itself needs no migration.

| Where (`w/www`) | Change |
|---|---|
| `app/Externals/DirectPayments/Subscriptions/SubscriptionActionsFactory.php` (new), `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | `actionsFor()` extracted unchanged into the factory; the self-service controller now takes the factory in its constructor instead of the three provider classes. **This is the only edit to existing subscription code.** Behaviour should be identical |
| `app/Http/Controllers/Brain/Staff/BrainStaffSubscriptionController.php`, `app/Services/Brain/Staff/StaffSubscriptionResolver.php`, `ResolvedSubscription.php`, `StaffSubscriptionPresenter.php`, `StaffAudit.php` | **BS6**: `GET/POST brain/staff/members/{ref}/subscription[/sync|/cancel|/plans|/change-tier]`. **These can cancel a subscription and change a plan at the provider** (Stripe/Square/PayPal), staff-side, for any member except the signed-in one. Every call needs a level-99 token, a reason (cancel, change-tier) and is audited |
| `StaffPaymentsController.php`, `StaffBillingController.php`, `routes/portal.php` | **BS8** `GET …/members/{ref}/payments`, **BS9** `GET brain/staff/billing/overview` (read-only) |
| `app/Models/BrainMembershipEvent.php` | Three new event constants |
| `tests/Feature/Brain/BrainStaffSubscriptionTest.php`, `tests/Concerns/CreatesStaffSubscriptionTables.php` | 33 new tests. The provider actions and the sync service are **faked**, so no test can call a payment provider |

**Deploy** as in section 11 (`vagrant provision brain --provision-with app-www`, then `php artisan route:clear` and `config:clear`). **Verify with no token**: `GET .../portal/api/v1/brain/staff/billing/overview` must answer **401** (404 = not deployed or stale route cache). After that `whoami` reports `subscription_staff` and `billing_overview` as `true`. **Nothing changes on the phone**: the app has no subscription screens yet (file 07). **Do not try cancel or change-tier against a real member**, even on the lab, until a sandbox subscription exists to test with.

BS11 (discount / billing amount / billing date) is deliberately **not** built; `subscription_pricing` stays `false`.

## 14. Addendum 2026-10-05 — AFS Brain app, file 07 (subscriptions): one server FIX and one addition. Read before deploying.

**1. A refund-route bug found and fixed (`complete/00_extras.md` E1).** `w/www/app/Http/Controllers/Externals/Processor/MoneyController.php` `toLedgerRows()` looked members up through
`brain_memberships.customer_id`, a column dropped by `2026_09_22_000002_add_payer_reference_num_to_brain_memberships`. On a migrated database that throws "Unknown column", so
`GET /portal/api/v1/money/ledger` **always** failed, and `POST /portal/api/v1/money/charges/{id}/refund` failed **after the provider had already refunded** (the response is built with that method). It now
resolves the member through the payer. The browser Financials → Money screen uses the same two routes, so this also affects it: if it has been showing errors, this is why.
**Please confirm on lab2 before deploying:** `php artisan tinker` → `Schema::hasColumn('brain_memberships','customer_id')` should be `false`. If it is `true` there, the old code ran and the fix is harmless.

**2. BS8 rows now carry `processor_mode`** (sandbox | live), found through each charge's **Direct Payment** → its processor, falling back to the charge's own processor. Additive; nothing else changes in the response.

**Run before deploying** (in `w/www`, with a reachable test database; none of these has ever been executed):
```bash
php artisan test --filter=MoneyLedgerMemberResolutionTest      # the E1 fix (5 tests; the provider refund is faked)
php artisan test --filter=BrainStaffSubscriptionTest           # BS6/BS8/BS9 (34 tests; provider and sync faked)
php artisan test tests/Feature/DirectPayments                  # regression for the self-service controller (section 13)
```
Deploy as in section 11 (no migration). **Phone:** `cd afsbrain_flutter && flutter run`. New: a **Subscription** and a **Payments** tab on each member, and a **Billing** destination.
**Do not try Cancel, Change plan or Refund on a real member, even on the lab.** They act at Stripe/Square/PayPal. Use a throwaway member with a completed *sandbox* subscription, and confirm the SANDBOX chip shows first.

PC state: `flutter analyze` clean, `flutter test` 399 pass, `tool/gates.sh` green.

## 15. Addendum 2026-10-05 (later) — AFS Brain app, files 08 to 10: two small `www` changes to deploy, and what to look at on the phone

Files 08 (usage and overrides) and 10 (UI and navigation) need **no** server change: the allocation and override routes already existed. File 09 (Checkpoint errors, afs-connect) needs these, both in `w/www`, no migration:

| File | Change |
|------|--------|
| `app/Services/Brain/Staff/StaffCapabilities.php` | `whoami` now reports **`checkpoint_errors`**, and `afs_connect` also needs the global-flag route. Without it the app does not show the Checkpoint errors list |
| `app/Http/Controllers/Portal/Admin/MembershipController.php` `updateAfsConnect` | **A real bug (`complete/00_extras.md` E2).** The www proxy validated twelve afs-connect source keys by hand and forwarded only validated keys, so the coordinator's other sources (`www_money`, `market_orders`, `joystick_engine`, `external_sdk`) were silently dropped on every save from the Brain admin (browser included). Now `'sources.*' => 'boolean'`; the coordinator still validates its own keys |
| `tests/Feature/Brain/BrainStaffCheckpointAndAfsConnectTest.php` (new, 7), `BrainStaffWhoamiTest.php` (updated) | The coordinator is faked; nothing leaves the process. **Never executed** (no database on the authoring PC) |

**Run before deploying** (in `w/www`, with a reachable test database):
```bash
php artisan test --filter=BrainStaffCheckpointAndAfsConnectTest
php artisan test --filter=BrainStaffWhoamiTest
```
Deploy as in section 11, then `php artisan route:clear`. **Verify with no token**: `GET .../portal/api/v1/memberships/checkpoint-errors` answers 401.

**Phone** (`cd afsbrain_flutter && flutter run`): a **Usage/Allocations** tab on each member (usage bars, overrides, add override, adjust counter), a **More** destination (Checkpoint errors with a count, afs-connect), and an afs-connect card on each member's Details tab. Things only the lab can answer: does lab2's **Parse** answer the allocation ledger (otherwise the tab says "ledger unavailable"), is the **coordinator** up (otherwise afs-connect says "unavailable"), and does the registry URL in `_env/lab2/.env` serve the registry. The **global afs-connect switch is estate-wide**, even on the lab. Use a lab member for overrides and counter edits.

The app lock (`APP_LOCK=true`, production) is off in the lab env; to try it, set it in a lab build by hand.

PC state: `flutter analyze` clean, `flutter test` 807 pass, `tool/gates.sh` green, one debug APK built (not installed).

## 16. Addendum 2026-10-05 (night) — AFS Brain app, plan 13 P10: the read-only infrastructure console. One migration and one route group to deploy.

Plan 13 (`plans/brain_flutter/13-brain-infrastructure.md`) is the app managing the Brain's own infrastructure. P10 is read-only. **Nothing here writes to any table except the two new ones.**

| File | Change |
|------|--------|
| `database/migrations/2026_10_05_000001_create_brain_infra_audit_and_history_tables.php` (new) | `brain_infra_audit` and `brain_infra_history`. **Run `php artisan migrate`.** No existing table changes. Empty until a later phase writes |
| `app/Services/Brain/Infra/{InfraJson,InfraResources,InfraOverview}.php` (new), `app/Http/Controllers/Brain/Staff/StaffInfraController.php` (new) | the read surface: `GET api/v1/brain/staff/infra/{overview,config,history,history/{id}}` and `{http/servers,http/tenants,http/clusters,http/regions,dns/servers,dns/zones,dns/subdomains,dns/named}[/{ref}]`. Group is `brain.only` + `portal.admin.auth` + `portal.level99`; every response is `Cache-Control: no-store` |
| `routes/portal.php`, `app/Services/Brain/Staff/StaffCapabilities.php` | the group; `whoami` now reports `infra`, `infra_config`, `infra_http`, `infra_tenants`, `infra_dns`, and `infra_dns_apply` / `infra_jobs` as `false` |
| `tests/Feature/Brain/BrainStaffInfraReadTest.php`, `tests/Concerns/CreatesInfraTables.php` (new), `BrainStaffWhoamiTest.php` (updated) | **never run** (no database on the authoring PC). The test **backs up and restores the single `brain_config` row**, deleting it for the duration; run it on the lab, not on a database whose config you cannot afford to lose |

**What the new routes return is, on purpose, everything:** every column of the row and the whole decoded `json`, secrets included. They are level-99-only and `no-store`. They are separate from the legacy `api/v1/brain/{config,dns,http}` routes, which this change does not touch.

**Run before deploying** (in `w/www`, a reachable **test** database):
```bash
php artisan test --filter=BrainStaffInfraReadTest
php artisan test --filter=BrainStaffWhoamiTest
```
Deploy as in section 11, then `php artisan route:clear`. **Verify with no token**: `GET .../portal/api/v1/brain/staff/infra/overview` answers 401.

**Phone** (`cd afsbrain_flutter && flutter run`): a fifth bottom destination, **Infra**. Look for: the warnings card (do the warnings match what you know of the fleet?), the load bar per server, each list, and a server's **JSON** tab against the browser's JSON for the same row (they should be identical). Questions only the lab can answer: does `GET /staff/infra/dns/zones` stay quick with the real zones (the list omits the generated zone files; the single zone does not), and is `brain_infra_history` created.

PC state: `flutter analyze` clean, `flutter test` 956 pass, `tool/gates.sh` green.

## 17. Addendum 2026-10-05 (night, later) — AFS Brain app, plan 13 P11: fleet writes. New write routes to deploy; no migration.

P11 lets the app **create, edit, retire, delete and roll back** HTTP servers, tenants, clusters and regions. It uses the two tables section 16 created. **This is the first change in the app that writes to the Brain's own fleet tables, so test it on the lab before the real Brain.**

| File | Change |
|------|--------|
| `app/Services/Brain/Infra/{InfraDiff,InfraValidator,InfraWarnings,InfraWriter}.php` (new), `InfraResources::describe()` (shared by reads and writes) | the engine: object-aware diff; validation (**errors**: not an IP, `active` not 0 or 1, required field missing, address or reference already used, cluster head equal to proxy, a JSON column that is not an object); **warnings** that must be acknowledged (server has tenants, server in a cluster, server is the config's default, region in use, a tenant's identity changed, a tenant record changed without touching the server, a cluster naming an unknown server, an unknown key); history snapshot and audit row in **one transaction** (an audit failure aborts the write); a per-row lock (423 when busy) |
| `app/Http/Controllers/Brain/Staff/StaffInfraWriteController.php` (new), `routes/portal.php`, `StaffCapabilities` | `POST api/v1/brain/staff/infra/http/{servers,tenants,clusters,regions}`; `PUT .../{ref}`; `POST .../{ref}/retire`; `DELETE .../{ref}`; `POST .../history/{id}/rollback`. `whoami` now reports `infra_write` |
| `tests/Feature/Brain/BrainStaffInfraWriteTest.php` (new), `BrainStaffWhoamiTest.php` | **never run** (no database on the authoring PC). Backs up and restores the single `brain_config` row; run on the lab |

**How a write behaves:** the body is `{document, expected_version, reason, acknowledged, dry_run}`. `expected_version` is the hash the read returned; if the row changed since, **409 `BS_E_STALE_RECORD`**, nothing written. A dry run (`dry_run: true`) returns the changes and warnings and writes nothing. A real write without a reason of 3 to 500 characters is refused; one without every code the dry run listed in `acknowledged` is **428 `BS_E_ACK_REQUIRED`**. `id` and `date` are never written (reported as `ignored`). Only columns whose JSON changed are rewritten.

**Run before deploying** (in `w/www`, a reachable **test** database):
```bash
php artisan test --filter=BrainStaffInfraWriteTest
php artisan test --filter=BrainStaffInfraReadTest
php artisan test --filter=BrainStaffWhoamiTest
```
Deploy as in section 16, then `php artisan route:clear`. **Verify with no token** that `POST .../infra/http/servers` answers 401, and that an account below level 99 gets 403.

**Phone:** with `infra_write` an **Edit** button appears on every server, tenant, cluster and region (and the config), **Add** on those four lists, **Retire / Delete** under the menu, and **Roll back to this** on a history snapshot. Try on the lab: add a throwaway server as JSON, edit it as a form, retire and delete it; change a tenant's `public_ipv4` and read the warnings; open the same row on two devices and save from both (the second must say it changed). **A save does not push anything to a server**: the Brain's own jobs read these rows later, exactly as they read what the web admin writes.

PC state: `flutter analyze` clean, `flutter test` 1030 pass, `tool/gates.sh` green.

## 18. Addendum 2026-10-05 (night, last) — AFS Brain app, plan 13 P12 and P13: config and DNS writes. Same routes group; no migration.

P12 lets the app replace the **one `brain_config` row**; P13 lets it write DNS servers, zones, subdomain A records and named.conf. Both go through the section 17 engine: `expected_version`, `dry_run`, acknowledged warnings, a reason, history and audit in one transaction.

| File | Change |
|------|--------|
| `app/Services/Brain/Infra/InfraConfigRules.php` (new) | config errors (`brain_host` and `portal_ip_login` entries must be addresses, `controller.keys[].name` unique, `hubspot.redirect_uri` https, `default_server` IPs) and warnings (`access_list_changed`, `mail_provider_position`, `consumer_secret_cleared`, `embedded_credential_stale`, `unknown_key`); `syncEmbedded` for the request flag `sync_embedded_urls` |
| `app/Services/Brain/Infra/InfraDnsRules.php` (new) | DNS: both zone shapes (`records[]` and `a[]/mx[]...`) read and **kept in the shape they have**; the SOA serial raised (`YYYYMMDD00`, strictly increasing, also within a day) when records changed and the document did not change it; `zone_invalid`, `zone_serial_not_increased`, `zone_record_only`, `last_dns_server_in_family`, `named_conf_edit` |
| `InfraWarnings`, `InfraValidator`, `InfraWriter`, `StaffInfraWriteController`, `routes/portal.php` | wired: `PUT /config/{ref}`; `POST/PUT/DELETE` and `…/retire` for `dns/servers`, `dns/zones`, `dns/subdomains`; `POST/PUT dns/named` |
| `tests/Feature/Brain/BrainStaffInfraConfigWriteTest.php`, `BrainStaffInfraDnsWriteTest.php` (new) | **never run**. The first replaces the single config row (backed up and restored); the second the named.conf row |

**Read before relying on DNS writes:** they change the **database only**. Nothing pushes a zone to a name server, and a zone save does **not** regenerate `json_zone_files` (finding F8: the Laravel reload routes are stubs). There is no `dns/apply` route and `whoami` reports `infra_dns_apply: false`; the zone editor says so.

**Run:** `php artisan test --filter=BrainStaffInfraConfigWriteTest` and `--filter=BrainStaffInfraDnsWriteTest` on the lab, then `php artisan route:clear`. **Phone:** open the Brain config, Edit, Sections: change `default_server` (no typed confirmation), then `host` (typed on production); change a Gitea token and use **Sync repo URLs**. Open a zone, Edit, Records: add a record and watch the serial line.

## 19. Addendum 2026-10-05 (last) — AFS Brain app, plan 13 P14: tenant transfer and jobs. A new switch in `.env`.

| File | Change |
|------|--------|
| `app/Services/Brain/Infra/InfraTransfer.php`, `InfraJobRunner.php` (new), `app/Http/Controllers/Brain/Staff/StaffInfraJobController.php` (new) | `POST …/infra/http/tenants/{ref}/transfer`, `GET …/infra/jobs[?tenant=]`, `GET …/infra/jobs/{key}`. The runner's payload is built **from the database** (not from the Brain's own routes: finding F4) with the same keys `moveTenants` used; locks on the tenant and the target server; an idempotent start (`client_key`); the audit row is written first; the payload file is `0600` and removed when the job is seen to end |
| `config/brain.php` | `infra_transfer_enabled` (env **`BRAIN_INFRA_TRANSFER`, default false**), `infra_transfer_dir`, `infra_transfer_log` |
| `StaffCapabilities`, `routes/portal.php` | `infra_jobs` (routes registered), `infra_transfer` (route registered **and** the switch on); `infra_dns_apply` stays `false` |
| `tests/Feature/Brain/BrainStaffInfraJobTest.php` (new), `BrainStaffWhoamiTest.php` | **never run**; they replace the runner and the progress store with fakes, so they prove the wrapper, **not that the runner works on any host** |

**The switch is off.** With it off a dry run returns the full payload and every warning, and a real start answers `403 BS_E_DISABLED`; the app hides the Transfer button. Turn it on only after confirming the runner (`core/scripts/background_runner.php --transfer-tenants`) on the lab host with a throwaway tenant and a second server. **The jobs screens show the payload in full, secrets included**, like every other infrastructure screen.

## 20. Addendum 2026-10-05 (last) — AFS Brain app, plan 13 P15 and P16: Apps and Storage. No server change.

The app is now a client of the existing `/v1/brain/apps` and `/v1/brain/storage` routes (level 99, `brain.only`); **nothing in `www` changes**. Two rows at the bottom of the Infra hub (when `whoami` reports `apps` and `storage`). A storage server's credential is shown **once**, exactly as the create or rotate route returned it, with a copy button; the app never stores or re-reads it. Apps settings and mail handlers quote their `revision`; a 409 is shown as "changed since you opened it" and never forced.

PC state: `flutter analyze` clean, `flutter test` 1158 pass, `tool/gates.sh` green.
