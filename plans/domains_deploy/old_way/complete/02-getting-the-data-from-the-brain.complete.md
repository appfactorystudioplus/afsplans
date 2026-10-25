# 02-getting-the-data-from-the-brain — completion record

**Plan file:** [`../02-getting-the-data-from-the-brain.md`](../02-getting-the-data-from-the-brain.md)
**Repos:** `w/members`, `w/www`
**Date:** 2026-10-22
**Status:** 🟡 P0 complete in code and tested; the two changes of this session are **not yet deployed to dev** (nothing uploaded, nothing committed). §2.7 P1 (remove the fallback) is not due.

## What the step asked for, and where it stands

| Plan item | State |
|---|---|
| §2.3 `POST internal/brain/build/data` and `build/tenant`, signed (`brain.only` + `brain.surface:MEMBERS`), each calling the old controller methods | **Built 2026-10-15 (plan `create_member_redo/11`)**: `w/www` `BrainBuildSurfaceController`. Checked live on dev today: both routes are in `route:list`. |
| §2.4 members: one signed helper, `getTenantData` fed by one call, `createWebsite` posts `build/tenant`, 404 fallback to the original calls, `getBrainConfig()` reads the same call | **Built (plan 11)**: `DomainController::viaBrain()`, `fetchBuildData()`, `legacyBuildData()`. |
| §2.6 Brain tests: valid signature gets the object; wrong key, replayed nonce, old timestamp, non-`MEMBERS` app refused; each key equals the old route's answer | Wrong key / non-MEMBERS / equality existed. **Replay and stale-timestamp tests added today.** |
| §2.6 Members tests: same `tenant` from the signed answer as from the eight old answers; 404 triggers the fallback | Existed (`DomainBrainSurfaceTest`). |
| §2.5 Release order | Brain first, members second: already true on dev (Brain has the routes). |

## What this session changed (`w/members`, `w/www` tests)

Reading `createWebsite` — the code that consumes the Brain data — showed it could not run once the data path was right:

* **Fatal error fixed.** `DomainController.php:536/540` called `Utils_generateRandomString()` unqualified. Inside the Laravel app that function does not exist (only `FunctionsController::Utils_generateRandomString` and a script-side global do), so the first real press of *Create website* ended in a 500 after the progress record was made. Now `FunctionsController::`.
* **A Brain error is a stop, not a half-empty build.** `fetchBuildData()` throws the new `App\Exceptions\BrainBuildDataException` when `build/data` answers anything but 200 with a `controller` config (401/422/500, or no config). `createWebsite` logs the reason and answers a plain 502; nothing is written. Before, the code went on to read `$brain["controller"]…` on null.
* **A domain the member does not have on the Brain** (`zones` empty) is refused with 404 before the progress record, the website row or the job file exist. Before, `$data['zones'][0]` was an undefined-key 500.
* **Empty `SUBDOMAIN_PROPERTIES_SUBDOMAIN`** falls back to the environment label (`VITE_SUBDOMAIN`); an empty value used to make the Brain answer 422.
* **No Brain-type server is not a crash.** The job's `brain.ipv4/username/password` are read null-safe and left empty. Checked: no script reads those three keys, and the dev Brain has no Brain-type server (live probe below), so a guard would have blocked every dev build.

Tests added: members `DomainBrainSurfaceTest` +6 (Brain error codes → 502 with no leak of the Brain's message; answer without config; unknown domain → 404 with one HTTP call; empty subdomain fallback; no bare `Utils_*` call in the controller; the job does not need a Brain-type server); www `BrainBuildSurfaceTest` +1 (replayed nonce, stale and future timestamp refused).

## How it was checked

* `w/members`: `vendor/bin/phpunit tests/Feature/DomainBrainSurfaceTest.php` — **23 tests, 91 assertions, OK**.
* `w/www`: `BrainBuildSurfaceTest` on the throwaway local MariaDB (port 3307, stopped afterwards) — **10 tests, 67 assertions, OK**.
* Only these two files were run (owner's instruction); no full suite.
* **Live, read-only, on dev** (`remote-run.sh`): the dev members app made a real signed `build/data` call (`probe.invalid`, no side effects): **HTTP 200**, keys `success, zones, config, dns_servers, http_servers, subdomains, regions, subdomain_properties, selected_server, ranking`; config has 16 `controller` keys; 1 DNS server, 1 HTTP server, 15 subdomain properties; `selected_server` = `mk7r2dwvaz1` at `45.79.42.137`, up; `zones` empty for the fake domain. The dev members copy already contains `viaBrain` (7 uses) and no unauthenticated `/dns/zones` call.

## Findings that matter to the next steps

* The Brain's only `Head` server on dev is **`45.79.42.137` — the same machine as the Brain and the DNS server**. The earlier tenants' host (`3.130.107.19`) is not selected. The owner is fixing the server rows; a first build will land on this machine, root ssh by password.
* No Brain-type server exists on dev (see above).
* Domain *registration* (list, add, search) is chosen per member by the Brain's `new_flow` setting (`DomainFlowController`), so members on the new flow do not use `store`/`search` of `DomainController`. The `zones/*` and `registrar/credentials` routes serve the old screens only. **`DomainController::purchase` stays reachable for every logged-in member** (route `web.php:440`) and does not check payment — to close with the old-flow domain screens, not here (OD2 left these out of step 02).

## Deviations from the plan text

* The two routes were built under plan 11 with four siblings (`zones/*`, `registrar/credentials`) the plan did not list; and `build/data` also returns the Brain's chosen server (`selected_server`, `ranking`).
* The plan's "tenant array equals the old eight-call build" test is `test_an_older_brain_is_read_with_the_original_eight_calls…` plus the signed-answer test, not one test comparing both.

## Not done / not verified

* Deploy of today's members change to dev (upload needs your word), then a real *Create website* — it also needs Redis and the shell scripts, which no test touches.
* §2.7 P1: removing the fallback and the unauthenticated calls — waits for the owner's date and for production's Brain to have the routes.
* Production's Brain release is still unanswered (step 07, question 1).
