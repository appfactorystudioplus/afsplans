# 02 — Getting the data from the Brain (P0)

Fixes **OF1**. This is the only change to how `getTenantData()` works; what it builds afterwards (the tenant, website, git settings, selected server, zones…) and everything downstream stay exactly as they are.

## 2.1 The change in one sentence

`getTenantData()` makes nine unauthenticated calls to `BRAIN_API_URL/...`; replace them with **two signed calls** to the Brain's existing members → Brain surface that return the **same data**, and fall back to the old calls when the Brain does not have the new routes yet.

## 2.2 Why a signed call and not a login

The Brain's routes need a bearer from `laravel_user_tokens` and a member at level exactly 99; members has no such user, and a service identity at level 99 was deliberately removed on 2026-08-29. The members → Brain **signed surface** already exists and is in use: `BrainSurfaceClient::post()` in members, `Route::prefix('internal/brain')->middleware(['brain.only'])` with `brain.surface:MEMBERS` in `w/www/routes/api.php`
(HMAC-SHA256 over timestamp, nonce, method, path and body hash; per-app key; replay protection; 404 on any deployment that is not the Brain). It needs no new credential and adds no user. (OD1 — alternatives in step 07.)

## 2.3 The two routes (Brain, `w/www`)

Added beside the `domains` group, same middleware, tagged `domains_deploy_old_way`:

| Route | Replaces | Returns |
|---|---|---|
| `POST /api/internal/brain/build/data` — body `{domain_name, member_reference_num, subdomain}` | `GET /dns/zones`, `/config`, `/dns/servers`, `/http/servers`, `/dns/subdomains`, `/http/regions`, `/subdomain_properties` (the eight reads in `getTenantData` lines 1005–1052, and `getBrainConfig()`) | one JSON object with the same keys, holding what each of those answers held today: `zones`, `config` (the `config.json` document), `dns_servers`, `http_servers`, `subdomains`, `regions`, `subdomain_properties` |
| `POST /api/internal/brain/build/tenant` — body = what `createWebsite` posts to `/http/tenants` today (`:540–549`) | `POST /http/tenants` | what `assignTenant` answers today |

The Brain does **not** re-implement the queries: each handler calls the same controller method the old route calls (`BrainDnsController::listZones`, `listServers`, `listSubdomains`, `BrainHttpServerController::listServers`, `listRegions`, `assignTenant`, `BrainController::getConfig`, `getSubdomainProperties`) with a built `Request` and unwraps the `JsonResponse`. Output is therefore identical to what members reads now, and a change to those controllers is picked up by both.

`config` carries every Brain credential; that is why the route is signed, `MEMBERS`-only and never forwarded to a browser (OF16, [step 03](03-robustness.md) §3.3).

## 2.4 Members (`w/members`)

In `DomainController::getTenantData()` and `createWebsite()` only:

1. A small method `brainBuild(string $path, array $payload): array` using `BrainSurfaceClient::post()`.
2. `getTenantData()` calls `build/data` once and fills `$zones`, `$brainConfig`, `$dnsServersResponse`… from it, **in the shapes the rest of the method already reads** (so lines 1054–1160 do not change).
3. `createWebsite()` calls `build/tenant` instead of `POST /http/tenants`.
4. **Fallback:** if the signed route answers 404 (an older Brain without it), members makes the original unauthenticated calls, exactly as today. This keeps production working during the release window and keeps this change reversible: nothing is removed.
5. `getBrainConfig()` (used by the registrar and by `git_settings`) reads the `config` of the same call.

The old domain screens (`store`, `update`, `destroy`, `search`, `purchase`) make the same unauthenticated calls and are broken the same way. They are **not** touched by this step (OD2); a member on the new domain flow uses the Brain surface already.

## 2.5 Release order

Brain first (the two routes), members second (it calls them; until the Brain has them it falls back). The kill switch for this change is simply to redeploy the previous members.

## 2.6 Tests

- Brain feature tests on the real test database (`testdb_phpunit_www`) with the signed-request helper used for `DomainsSurfaceTest`: a valid signature gets the object; a wrong key, a replayed nonce, an old timestamp and a non-`MEMBERS` app are refused; each key of the answer equals what the matching old route returns for the same fixture (compare the two in one test).
- Members feature tests with `Http::fake()` (the `DomainSurfaceControllerTest` pattern): `getTenantData` builds the same `tenant` array from the signed answer as from the eight old answers; a 404 from the signed route triggers the fallback; the job file is unchanged.
- No test may need the shell scripts.

## 2.7 Work

| Phase | Work |
|---|---|
| **P0** | The two Brain routes and handlers; the members `brainBuild` method, `getTenantData` and `createWebsite` changes with the fallback; tests as §2.6. |
| **P1** | Remove the fallback and the unauthenticated calls once production's Brain has the gate and the routes (owner's date). |
