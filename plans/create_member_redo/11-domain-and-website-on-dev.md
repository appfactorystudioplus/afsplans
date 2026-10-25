# 11 — Add or buy a domain, and deploy a website: the old flow, on the dev environment

Status: steps 2 to 4 of section 7 are DONE 2026-10-15 in code (`w/www`, `w/members`); step 1 (the read-only dev checks) was done earlier; steps 5 and 6 (a real dev
run: add a domain, build a website, buy a name) are NOT done and need your word. See "Done 2026-10-15" at the end. Code blocks above that section show the design; the code is the source of truth.

Paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/` unless stated. "Members" is `w/members`, "the Brain" is `w/www`.

## 1. Ground rules (owner)

* The plan of record is `packages/afsserver/plans/domains_deploy/old_way/` (its steps 02 to 05 and its findings OF1 to OF17). This plan
  applies it to add, search, purchase and deploy together, and nothing else is used.
* The environment is the **dev environment** (the dev server and the `www15` / `members15` hosts), not the Vagrant lab. **Dev is real
  (owner, 2026-10-15):** it behaves exactly as production does and differs only in its URLs. Real DNS is modified and Let's Encrypt
  runs. There is **one server, `45.79.42.137`**: it is the DNS server, the Brain and the tenant host. The lab
  findings in the old_way plan (OF2 to OF4: the lab's folders, its web VM, its setuid wrapper) are not this plan's concern; the dev
  equivalents are listed in section 6 as things to check.
* **Nothing in this mechanism may be level-99 restricted.** It is an automated mechanism restrained by the owner's level 99, so the
  calls it makes must not require the member (or anything else) to be level 99. See section 4.

## 2. What the old flow does today (members `app/Http/Controllers/Brain/DomainController.php`, read in full for this plan)

| Action | Members method (line) | What it calls |
|---|---|---|
| List domains | `index` (104) | Brain `GET /dns/zones?member_reference_num=` |
| Add a domain | `store` (162) | Brain `GET /dns/zones` (exists?), `GET /config` when `own`, `POST /dns/zones` |
| Remove / rename | `destroy` (241), `update` (274) | `ownsZone()` (77): Brain `GET /dns/zones`; then `DELETE` / `PATCH` |
| Search a name | `search` (584) → `_searchNameCom` (608) | Brain `GET /config` for the registrar key, then Name.com `domains:search` |
| Buy a name | `purchase` (654) → `_runNameCom` (679) | Brain `GET /config`, then Name.com `domains` (buy) and `domains/{name}:setNameservers` |
| Create the website | `createWebsite` (395) → `getTenantData` (1002) | eight Brain reads (`/dns/zones`, `/config`, `/dns/servers`, `/http/servers`, `/dns/subdomains`, `/http/regions`, `/subdomain_properties`), then `POST /http/tenants`; writes the job JSON; `popen` of `background_runner.php` → `commands.sh` → `commands.expect` → `app_install.sh` on the tenant host |
| Progress | `getProgress` (784), `recordWebsiteProgress` (808) | Redis, then the database |

All Brain calls above carry only an `Accept` header today. The registrar environment is chosen from the request host: `members15.appfactory.studio`
selects the `development` key, anything else `production` (`_searchNameCom` line 610, `_runNameCom` line 681); both buy through
`https://api.dev.name.com/v4`. A purchase is: the browser charges through Direct Payments, then calls `purchase`, then saves the zone with
`store` (`own = 1`).

## 3. Why none of it runs against the Brain today

The Brain's `GET /config`, `dns/*` and `http/*` routes sit behind `portal.admin.auth` (a bearer token) **and** `portal.level99`
(old_way finding OF1). Members sends no token, so every one of the calls in section 2 is refused, which is also why `store`,
`search`, `purchase` and `createWebsite` fail together. `GET /config` returns the Brain's whole configuration, every credential included.

## 4. Decision needed from the rule "nothing is level-99 restricted"

Two ways satisfy it, and only one is safe.

| | How | Result |
|---|---|---|
| **A. Remove the gate** | take `portal.level99` (and the token requirement) off `config`, `dns/*`, `http/*` | **Not recommended.** Anyone who can reach the Brain could read `/config` (all credentials) and edit DNS and the server list. The gate is the only thing between the internet and those routes. |
| **B. A signed call from members (recommended)** | add Brain routes for exactly what the old flow needs, protected by the existing signed members to Brain surface (`brain.only` + `brain.surface:MEMBERS`, an HMAC with the members app key), and call them from members with `BrainSurfaceClient` | No member level is involved anywhere, so nothing is level-99 restricted. Access is "this is the members app", not "this member is level 99". This is old_way step 02 (OD1 option A). |

The recommendation is **B**. `GET /config` is never returned to members whole: the registrar key and the build data are returned as the specific
objects the old code reads (old_way 02 §2.3), and never to a browser.

## 5. The change (Brain routes and members calls)

### 5.1 Brain: signed routes, MEMBERS key only

In `w/www/routes/api.php`, inside the existing `internal/brain` group, beside the other `brain.surface:MEMBERS` routes. Each handler calls
the same controller method the old route calls, with a built `Request`, and unwraps the `JsonResponse`. No query is re-implemented.

| Route | Replaces | Returns |
|---|---|---|
| `POST /api/internal/brain/build/data` `{domain_name, member_reference_num, subdomain}` | the eight reads in `getTenantData` | one object with the same keys (zones, config, dns servers, http servers, subdomains, regions, subdomain_properties) |
| `POST /api/internal/brain/build/tenant` | `POST /http/tenants` | what `assignTenant` answers today |
| `POST /api/internal/brain/zones/list` `{member_reference_num, domain_name?, reference_num?}` | `GET /dns/zones` (`index`, `store` check, `ownsZone`, `update` fetch) | `{zones: [...]}` |
| `POST /api/internal/brain/zones/save` `{...}` | `POST /dns/zones` and `PATCH` | `{zone: {...}}` |
| `POST /api/internal/brain/zones/delete` `{reference_num, member_reference_num}` | `DELETE` | success |
| `POST /api/internal/brain/registrar/credentials` `{env}` | `GET /config` in `getRegistrarCredentials` | only the registrar's key entry for that environment, never the whole config |

The Brain trusts the signed app and the `member_reference_num` it is given, as the other surface routes do; members derives that value from the
verified Checkpoint token (`currentMemberReferenceNum()`), never from the request.

### 5.2 Members: one helper and the replacements

```php
// DomainController
private function brain(string $path, array $payload = []): array
{
    $result = app(BrainSurfaceClient::class)->post($path, $payload);   // signed; same client the billing trigger uses
    return $result['ok'] ? ($result['body'] ?? []) : [];
}
```

`index`, `store`, `destroy`, `update`, `ownsZone`, `getBrainConfig`, `getRegistrarCredentials` and `getTenantData`/`createWebsite` switch from
`Http::get($this->brainApiUrl() . ...)` to `$this->brain('zones/list', ...)` and so on. Following old_way 02 §2.4, the old calls remain as a
fallback only when a signed route answers 404 (an older Brain), and are removed once the dev Brain has the routes.

### 5.3 What is NOT changed

The job JSON's shape, the scripts, the `www` / `www-own` pair, certbot, the progress model and the Name.com driver stay (old_way 00 §0.4).

### 5.4 One environment switch: `VITE_SUBDOMAIN` (owner)

The application learns which environment it runs in from one place, `VITE_SUBDOMAIN` in `.env`:

| `VITE_SUBDOMAIN` | Environment |
|---|---|
| `www15` | development |
| `www` | production |
| missing, empty or anything else | **production** (the default is `www`) |

Today the answer is scattered as literals in members, and they are wrong on dev (there is no `dev/members15` folder):

| File | Line(s) | Literal |
|---|---|---|
| `app/Http/Controllers/FunctionsController.php` | 31, 52, 70, 99 | `members15` → `dev/members15` |
| `app/Http/Controllers/Brain/DomainController.php` | 465-466, 934 | the same, to build `root_directory` and the runtime config |
| `app/Http/Controllers/Brain/DomainController.php` | 610, 681 | `$request->getHost() === 'members15.appfactory.studio'` picks the `development` or `production` registrar key |
| `core/scripts/background_runner.php` | 93, 1074 | `www15` → `dev/members15`, else `members` |
| **Brain side, `w/www`** `app/Http/Controllers/FunctionsController.php` | 611-612, 712-713, 1504 | `www15` → `dev/www15` |
| `w/www` `FunctionsController.php` | 684-685 | `www15` → `dev/www` (the only one that matches the real dev folder) |
| `w/www` `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | 578-579 | `www15` → `dev/www15`, passed to `--update-cron-task` |
| `w/www` `app/Http/Controllers/Brain/BrainHttpServerController.php` | 560 | `dev/www15` |
| `w/www` `app/Http/Controllers/Email/EmailManagerController.php` | 59 | `www15` → `dev/www15` |
| `w/www` `app/Services/Brain/Infra/InfraTransfer.php` | 278 | `dev/www15` |

The `w/www` literals disagree with each other (684-685 says `dev/www`, the rest say `dev/www15`) and all but one are wrong on dev, where the Brain's folder is
`/home/appfactorystudio/websites/dev/www`. They get the same treatment: one helper that returns the app's own folder from `base_path()`, no literal.

The change, members side (cache-safe: `env()` only in a config file, as the file's own F9 note requires):

```php
// config/app_environment.php
return [
    'subdomain' => env('VITE_SUBDOMAIN', 'www'),
];
```

```php
// app/Support/AppEnvironment.php
final class AppEnvironment
{
    public static function isDevelopment(): bool
    {
        return config('app_environment.subdomain') === 'www15';   // anything else, or nothing, is production
    }

    public static function name(): string
    {
        return self::isDevelopment() ? 'development' : 'production';
    }
}
```

Uses: the registrar key becomes `$credentials[AppEnvironment::name()]` in `_searchNameCom` and `_runNameCom`. The script folder is no longer chosen by
environment at all: `root_directory` is the app's own folder, `base_path()` (`dev/members` on dev, `members` on production), and
`background_runner.php` and `RunCommandBG1` read `root_directory` from the job JSON instead of recomputing it from `www15`. The other literals in
the table are replaced the same way. The Brain (`w/www`) already reads `VITE_SUBDOMAIN` through `config('afs_connect.subdomain')` with the default `www`.

### 5.5 Choosing the best server (owner): up, and closest to the member

Replace "first active `Head` row" with a selection that checks the server is up and prefers the server nearest the member. It runs on the Brain, inside
`build/data` (section 5.1), which returns the chosen row as `selected_server` (members' `selectTenantServer()` then only reads it, falling back to the old
first-`Head` rule when an older Brain does not send one).

**Nothing is cached (owner).** Every create-website request recomputes everything from scratch: the server list, which servers are up, the tenant counts,
each server's coordinates and the distances. No `Cache`, Redis, file or table is used to remember an up/down answer, a count, a coordinate or a distance.

1. **Eligible:** `active = 1`, `server_type = 'Head'`, `draining = 0`, and fewer active tenants than the server's limit: the `max_tenants` column when set,
   otherwise the old formula from the row's `json` (`specs.ram_size * 1000 / specs.tenant_size`, as `SelectServer1` computes it), otherwise no limit.
2. **Up and available**, checked live with a 3-second timeout (owner confirmed): a TCP connection to the ssh port (22), because the build logs in over ssh, and to the
   web port (443, else 80), because the site is served from there. The check opens connections only; it logs in to nothing.
3. **Distance from the member (straight line, owner):** the member's address is read by the Brain from the member's own record (`city`, `state`, `zip_code`,
   `country_code`), never from the request. Two coordinate pairs are looked up live and the great-circle distance between them is computed:
   * the server's coordinates: MaxMind's city lookup of its `public_ipv4`, called from the Brain over HTTPS with the `maxmind` key (client id and secret) from the
     Brain config. This is the same lookup `GetDistanseBetweenServerAndMember1` in `members/core/scripts/background_runner.php` makes through its script-side library;
   * the member's coordinates: Google's Geocoding of that address, with the `google_api_key_1` key from the Brain config (the key `background_runner.php` passes as
     `google_geocoding_api_key`);
   * the distance: the haversine formula on the two pairs. Google's Distance Matrix (road distance) is not used.
4. **Choose** the up server with the shortest distance; ties, and servers whose distance could not be obtained, are ordered by fewest active tenants, then highest
   `weight`, then lowest `id`. If no server has a distance (a key is missing, a service is down, the member has no usable address), the choice is by tenants alone.
5. **If none is up:** the request is refused with a clear message ("No server is available"); nothing is built or written. The answer lists, per candidate, why it was
   rejected (`down: ssh`, `full`, `draining`) and the distance it got, so a choice or a refusal can be explained without reading logs.

```php
// w/www, called by the build/data handler. No caching anywhere in here.
final class BuildServerSelector
{
    /** @return array{server: ?array, ranking: list<array{reference_num: string, up: bool, tenants: int, distance_m: ?int, reason: ?string}>} */
    public static function pick(array $member): array
    {
        // 1 candidates (brain_http_servers) and tenant counts (brain_http_server_tenants), read now
        // 2 for each: reachable(ip, 22) && (reachable(ip, 443) || reachable(ip, 80))
        // 3 member = geocode($member address) once per request (Google); for each up server: haversineMeters(serverCoordinates(ip) /* MaxMind */, member)
        // 4 sort by distance (nulls last), then tenants, weight, id; the first is the server
    }

    private static function reachable(string $ip, int $port): bool
    {
        $socket = @fsockopen($ip, $port, $errno, $error, 3.0);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
```

Notes on the owner's existing code that the plan carries over or drops:

* The disabled `SortAvailableServers1` sleeps 3 seconds between servers "so as to not make rapid calls to google and maxmind". The new code makes one MaxMind call per up candidate and one Google geocoding call per request, and does not sleep; if a provider rate-limits, a pause goes back in.
* The MaxMind client that `background_runner.php` loads (`core/scripts/includes/libs/goeip`) is a script-side library the Brain does not have. **Decided (owner):** the Brain
  calls MaxMind's web service over HTTPS with the same account id and key (the endpoint that library wraps), so no composer package is added.
* `GetMemberSupported1` (approved countries) and the port-block allocation in the same file are not part of this selection and are left alone.
* The columns `health_state` and `last_heartbeat_at` exist on the table but are filled only by an agent that is not part of the old flow, so they are not used.

## 6. Dev checks (read-only, run 2026-10-15 on the dev server, with the owner's approval)

Nothing was changed. Credentials were read inside the shell and never printed.

| # | Check | Result |
|---|---|---|
| 1 | Hosts and folders | Apache (port 808, behind nginx): `members15.appfactory.studio` → `/home/appfactorystudio/websites/dev/members/public`, `www15.appfactory.studio` → `.../dev/www/public`. **There is no `dev/members15` or `dev/www15` folder** (`dev/` holds `auth communications coordinator docs dream_graph flow market members notes pdf_editor www`). |
| 1b | What the code expects | `DomainController::createWebsite` sets `root_directory` to `/home/appfactorystudio/websites/dev/members15` when the request URL contains `members15` (line 464-469), and `background_runner.php:93` maps `www15` to `dev/members15`. On dev both point at a folder that does not exist. |
| 2 | Tenant host and tooling | The dev Brain's active tenants were built on `3.130.107.19` (`node1.appfactory.studio`, a `Head` server): `brain_http_server_tenants` has 4 active rows, three of them `laurensmenu.net` on that IP, and one `laurensmenu.com` on this server. Other `Head` rows: `3.138.213.208` active, `45.33.125.209` inactive. The Brain server (`45.79.42.137`) has `accepts_tenants = 0`. Tooling was checked on the Brain host only: node v22.13.0 and npm 10.9.2 (under `/usr/local/.nvm`), php 8.4.18, composer, git, expect, g++, 7.6 GB RAM, 8.7 GB swap, 4 CPUs. The tenant host (where the build runs) was not checked. |
| 3 | Scripts and the setuid wrapper | `members/core/scripts`, `background_runner.php`, `commands.sh`, `commands.expect`, the builder folder: all `apache:apache 755`. **`bin/afs-website` is `apache:apache 755`: not root-owned and not setuid**, so the pipeline would run as apache with no extra privilege. |
| 4 | The Brain's gate and release | In the current code `GET /subdomain_properties` needs `portal.admin.auth`; `/config`, `/dns/*`, `/http/*` need `portal.admin.auth` **and** `portal.level99` (`routes/portal.php`, the group commented "staff-only, 2026-10-05"). Members sends no token, so all fail. The dev `routes/portal.php` and `routes/api.php` differ from the local source (different checksums; dev's `portal.php` has 29 more lines before this group) but the gate logic in that region is the same. Dev's `api.php` has `billing/enforce`, `domains` and `apps/{app}` surface routes and **no** `build/data`, `build/tenant`, `zones/*` or `registrar/credentials`: the section 5.1 routes are new work. |
| 5 | Subdomain properties | `subdomain_properties` has `www` and `www15`, 14 properties each, `SystemUser = appfactorystudio`, `Domain = appfactory.studio`. Members dev `.env`: `SUBDOMAIN_PROPERTIES_SUBDOMAIN="www15"`, `SUBPATH="members15"`, `LETSENCRYPT_CHECK=true`, `BRAIN_API_URL=https://www15.appfactory.studio/portal/api/v1/brain`, `QUEUE_CONNECTION=database`. www dev `.env`: `IS_BRAIN=true`, `VITE_SUBDOMAIN="www15"`. |
| 6 | The web user and the wrapper | php-fpm pool `user = apache`, `group = apache`; httpd and php-fpm workers run as apache (plan 10). With a non-setuid wrapper the build runs as apache. |

Also seen: one active DNS server row, `ns1.appfactory.studio` at `45.79.42.137` (this same machine); `members_websites` has 2 rows; 3 zones (2 own).
The `brain_config` JSON could not be read with `json_extract` (it is not plain JSON), so the registrar key was not looked at.

### What the checks change in this plan

* **Folders:** replace the `dev/members15` literals as described in section 5.4, so dev uses `dev/members` through `base_path()`.
* **The wrapper (old_way OD5):** decide whether dev runs as plain apache (as it is now) or gets the setuid wrapper. The first dev run will show whether anything in `commands.sh` needs root locally.
* **`LETSENCRYPT_CHECK=true` stays** (owner): dev issues real certificates through certbot, like production. Old_way OF14 (the
  per-registered-domain issuance limit, and the wildcard for `*.sites.<zone>`, old_way step 05) therefore applies on dev too.
* **Real DNS** (owner): the pipeline pushes zone files to the DNS server, `ns1.appfactory.studio` at `45.79.42.137`, which is this
  same machine. That is intended.
* **The wrapper** (owner): the owner is fixing the permissions of `bin/afs-website`; not part of this plan.

### Which server the build targets

The `brain_http_servers` rows are the owner's to fix (owner, 2026-10-15). What this plan changes is how a server is **chosen** (section 5.5),
because today `selectTenantServer()` (`DomainController.php:992`) returns the first active `Head` row in list order, with no check that it
exists: on dev that is `node1.appfactory.studio` at `3.130.107.19`.

## 7. Order of work (after approval)

1. The read-only dev checks in section 6.
2. The Brain's signed routes with feature tests on the real test database (signed request accepted; wrong key, replayed nonce and old
   timestamp refused; each answer equals what the old route returns for the same fixture).
3. The members helper and the replacements in section 5.2 with `Http::fake()` tests, including the 404 fallback.
   Also `AppEnvironment`, the replaced literals (section 5.4) and the `BuildServerSelector` with tests (a down server is skipped; none up is a refusal;
   nearest up server wins, then fewest tenants; no distance data falls back to tenants; a down server is skipped; none up is a refusal).
4. The value fixes in old_way 03 §3.5: V1 (the Brain's tenant reference), V2 (`CreatedBy`), V4 (one generated `db_password`), plus
   marking a failed build as failed (old_way OD2 recommends these now).
5. A dev run: add an owned domain, then create a website on it and watch the progress bar to `complete`.
6. A dev purchase: Name.com sandbox search and buy, the Stripe sandbox charge through Direct Payments `eq4W10iF8` (which reaches the
   charge hook of plan 04), then `store` with `own = 1`.

## 8. Open

* Confirm B (section 4), including that `GET /config` is never exposed to members.
* The registrar key lives in the Brain config; is the `registrar/credentials` route acceptable, or should the Name.com calls move to the
  Brain entirely?
* Whether the `domains:*` scheduled commands (plan 09) have any role in the old flow. This plan assumes they do not.
* Purchases use `api.dev.name.com` for both environments; real production purchases need the real API. Out of scope here.
* The `brain_http_servers` rows: the owner is fixing them (section 5.5 only changes how a server is chosen).
* Whether registering a bought name should really go through Name.com's development API: `_runNameCom` and `_searchNameCom` post to
  `https://api.dev.name.com/v4` in both environments. If dev must behave as production and register real names, the URL (and the key)
  changes; that is outside the other items here.
* DECIDED (owner): the up check uses ports 22 and 443/80 with a 3-second timeout.
* Any `VITE_SUBDOMAIN` other than `www15` is treated as production. Say if another value (for example a second dev label) must count as development.
* DECIDED (owner): distance is straight line (haversine) between the server's MaxMind coordinates and the member's geocoded address.
* DECIDED (owner): the Brain calls MaxMind's web service over HTTPS; no GeoIp2 package is added.
* The member's address needs a usable `city`/`state`/`zip_code`/`country_code`; with an address Google cannot geocode, the choice falls back to tenants alone (section 5.5, rule 4).

## Done 2026-10-15

Committed in `w/www` and `w/members`, nothing pushed, nothing run on any server, no real DNS, Let's Encrypt, Name.com or Stripe call made.

**Brain (`w/www`)**
* `BrainBuildSurfaceController` and six signed routes in `routes/api.php` (`brain.only` + `brain.surface:MEMBERS`): `build/data`, `build/tenant`, `zones/list`, `zones/save`, `zones/delete`, `registrar/credentials`. Each calls the
  same controller method the old route calls with a built request (`getConfig`, `listServers`, `listZones`, `listSubdomains`, `listRegions`, `getSubdomainProperties`, `assignTenant`, `saveZone`). `zones/save` and
  `zones/delete` also refuse (404) a zone that belongs to another member, which the old routes did not check; `registrar/credentials` returns only the registrar's entry for the asked environment (`development` or `production`),
  never the whole config; `build/data` returns the config (the build needs it) to the MEMBERS key only.
* `BuildServerSelector` (`app/Services/Brain/Build/`), called by `build/data` (section 5.5): active `Head`, not draining, under its limit (`max_tenants`, else the old `ram_size * 1000 / tenant_size`), up (ssh 22 and 443 else 80,
  3-second timeout, connections only), nearest by straight line (haversine) between MaxMind's coordinates for the server (web service over HTTPS, the `maxmind` key) and Google's geocoding of the member's address (`google_api_key_1`),
  then fewest tenants, highest weight, lowest id; nothing cached. None up is a 503 `NO_SERVER_AVAILABLE` with the per-server reasons. Failed, removed and orphaned tenants are not counted.
* `App\Support\AppFolder` replaces the `www15` to `dev/www15` literals (which name a folder that does not exist) in `FunctionsController::RunCommand`, `RunCommandBG`, `RunScriptBG`, `DirectPaymentsController::saveDirectPayment()`
  (this is also plan 10's 3.2a: the path passed to `--update-cron-task`), `EmailManagerController`, and uses `base_path()` for `root_directory` in `BrainHttpServerController` and `InfraTransfer`. Where the app does not live under the user's
  `websites` folder the label is only a fallback and maps `www15` to `dev/www`.
* Tests: `BrainBuildSurfaceTest` (9; every route refuses an unsigned, wrongly signed or non-MEMBERS call; `build/data` equals the old controllers' answers for the same data; refusal when none is up; the tenant row; zone ownership;
  soft delete; credentials limited to one environment), `BuildServerSelectorTest` (12), `AppFolderTest` (3). The www Unit, Direct Payments and Brain suites pass on a throwaway local MySQL (329, 110 and 845 tests).

**Members (`w/members`)**
* `config/app_environment.php` and `App\Support\AppEnvironment` (`VITE_SUBDOMAIN`: `www15` is development, anything else production, default `www`); the registrar key is `credentials[AppEnvironment::name()]` instead of a host comparison.
* `DomainController`: a `viaBrain()` helper that makes the signed call and falls back to the original unauthenticated call only when the Brain answers a plain 404 without a `success` key (an older Brain; a 404 with `success` is the
  route saying "no such zone"). `index`, `store`, `destroy`, `update`, `ownsZone`, the registrar credentials, `getTenantData` (one `build/data` call, the Brain's `selected_server`, the old eight calls and first-`Head` rule as the fallback)
  and the tenant post (`build/tenant`) use it. `createWebsite` answers 503 with a plain message when no server is available, before anything is written.
* `root_directory`, `FunctionsController::RunCommand*` and `writeRuntimeConfig` use `base_path()`; `background_runner.php` `RunCommandBG1` takes the job's `root_directory` (no `dev/members15`).
* Old_way fixes now done: **OF16** (the browser gets only `success`, `tracker_key`, `website_reference`; it used to get the Brain config, the DNS and server logins and the whole job); **V2** (`CreatedBy` row filled: `app_install.sh` now reads
  `tenant.CreatedBy`); **V4** (one `Str::random(24)` database password per tenant, used for `CREATE USER`, the `.env` files and the row; `app_install.sh` fails the build if the job has none); **V1** was already covered by plan 01
  (the random `reference_num` row is gone); and the failed-build status (`ProgressController` turns the website off with `status = failed` and the reason when the final `failed` arrives, marks it `active` on `completed`).
* Tests (run with www's phpunit, as members has none): `DomainBrainSurfaceTest` (15), `WebsiteBuildOutcomeTest` (3); the existing domain flow, domain surface, BrainRun and enforcement trigger tests still pass.

**Not done**
* Section 7 steps 5 and 6: a dev run (add an owned domain, build a website to `complete`, a Name.com sandbox purchase with the Stripe sandbox charge through `eq4W10iF8`). They change real DNS and issue real certificates on dev and need your word.
* The old_way items not listed in section 7 step 4: V3, V5, the `app_install.sh` failure checks beyond the missing password (OF13), re-runnable actions, file permissions and log lines (OF11), progress retries (OF15), and the per-build token.
* `getBrainConfig()` is gone from members; the fallbacks stay until the Brain everywhere has the routes (old_way 02 P1).
* Not verified: the signed routes against a real Brain, the selector against real MaxMind/Google/ssh, `app_install.sh` (bash 4 only) and `background_runner.php` on a real host, the browser flow. `createWebsite` beyond its refusal path and the response shape needs Redis and the shell scripts and was not run.
* Still open for you (section 8): whether the Name.com calls should use the real API on dev (both environments post to `api.dev.name.com`), which `domains:*` commands (plan 09) the old flow needs, and any `VITE_SUBDOMAIN` value other than `www15` that should count as development.
