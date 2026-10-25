# 06 — Server selection: completion report

> **Status: built and tested (P0, and from P1: the reservation sweep, the staff dry run as a service, region policy, the agent-version filter). Not built: the health and disk inputs (there is no feed to read), `waiting_capacity`, the HTTP route, P2.**
> Carried out 2026-10-08 against [06-server-selection.md](../06-server-selection.md). Nothing is committed. The code is in `w/www` (the Brain); one dead method was removed from `w/members` (§4.8).
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

One service whose decision is **filtered, scored, reserved and explained**, replacing "the first active server of type `Head`" (audit F17), the unrouted least-tenants query, the dead geo-distance code (F18) and the race in which two deploys take the last slot (F19).

| Plan section | Built as |
|---|---|
| §6.1 where it runs | `App\Services\Brain\Servers\HttpServerSelector` — `evaluate()` (a **pure function**), `dryRun()` (explain, reserve nothing), `reserve()`, `start()`, `release()`, `sweepExpired()`, `loads()` |
| §6.2 hard filters | `inactive · not_accepting · draining · no_capacity_configured · capacity_full · unreachable · preflight · agent_version · region · degraded`, each excluded server carrying its code and a sentence |
| §6.3 score | `load 0.6 · health 0.2 · region 0.15 · weight 0.05` (config), deterministic; ties → **least recently assigned** (never-assigned first), then lowest id; an idle fleet round-robins (tested: a, b, c, a, b, c) |
| §6.4 reserve | one transaction: the claim row and **all server rows locked in id order**, load counted under the lock (live tenants + live reservations), the tenant row inserted `reserved` with `reserved_until` (30 min) so it counts at once; `UNIQUE (active_claim)` refuses a second website for a claim; expired reservations are `failed` by `domains:servers-sweep` (every five minutes) and **do not count even before the sweep runs** |
| §6.5 explain | the full `selection` (every server considered, the filter or the score components, the chosen one, the weights version, the time) is returned and written as a `server_selected` event on the claim; step 07 stores it in `brain_website_deployments.selection` |
| §6.6 stickiness | selection runs once per claim; a re-deploy does not call it (07), a move calls it for the target (12) |
| §6.7 removals | `createWebsite2` (unrouted) removed from `members`; the rest in §4.8 |

## 2. Files

All paths under `C:\projects\vagrant\afs-eco\w\www` unless stated.

- `app/Services/Brain/Servers/HttpServerSelector.php` — the whole service.
- `app/Console/Commands/DomainsServersSweep.php` + the schedule in `routes/console.php`.
- `config/domains.php` — the `selection` block (weights, reservation minutes, the optional filters).
- `database/migrations/2026_10_08_000005_add_purchase_tables_and_selection_columns.php` — `last_assigned_at` on `brain_http_servers`, and the **tenant-host opt-in** (§3). (Shared with step 05's tables.)
- `tests/Feature/Brain/Domains/Servers/HttpServerSelectorTest.php`, `tests/Support/server_reserve_worker.php` (one of the competing processes).
- `C:\projects\vagrant\afs-eco\w\members\app\Http\Controllers\Brain\DomainController.php` — `createWebsite2` deleted (180 lines).

## 3. Decisions made on the way

- **Role is carried by `accepts_tenants`.** The plan's "role" filter says tenants go on `Head` servers and the Brain itself only when an operator sets it. Step 03 had defaulted `accepts_tenants` to 1 for every row, which would have made a Brain-type server a candidate by default. Migration `…000005` fixes that: the existing rows are set **once** from `server_type` (`Head` = 1, anything else = 0), and a **new** server defaults to 0 — it takes no tenants until an operator enables it. So there is no separate role filter to disagree with the flag (a test proves the default). The three optional filters below are off until something writes the columns they read.
- **Capacity** is the typed `max_tenants`; if null, `ram_size × 1000 ÷ tenant_size` from the server JSON (the old code's formula: 16 GB and 200 MB per tenant = 80); if neither exists the server is excluded as `no_capacity_configured`, never assumed unlimited.
- **A legacy tenant row with no `server_ref` counts against the server whose address it carries**, so the existing sites (e.g. the three lab tenants on `brain`) are part of the load from the first day.
- **An unknown member location is neutral for everybody** (`region` = 0.5 on every server), and **missing monitoring is `unknown` (0.5), not a failure** — as the plan asks.

## 4. Where the build differs from the plan text

1. **The monitoring input does not exist, so health and disk are not filtered or scored from it.** The plan says to read "the latest monitoring report" and to confirm it against the shipped schema. The shipped monitoring (`plans/brain_flutter/14`) is read by the **phone** from an agent on each server; `InfraMonitorRules` states that "the Brain never reads it to act", and nothing stores reports in the database. So: the **disk** filter (`min_free_gb`) is **not built**, and **health** reads `brain_http_servers.health_state` (`healthy | unknown | degraded`, default `unknown`), which nothing writes yet — step 07's tenant agent is the intended writer. The selector is ready for it (degraded is excluded and scores 0, healthy scores 1).
2. **Reachability, preflight and agent version are real filters but OFF by default** (`DOMAINS_SELECT_REQUIRE_REACHABILITY`, `…_REQUIRE_PREFLIGHT`, `…_REQUIRED_AGENT_VERSION`). `last_heartbeat_at`, `preflight_ok_at` and `agent_version` have no writer until step 07; enabling a filter now would exclude every server. An enabled filter excludes a server that has **never** reported, with the reason. Agent versions compare as versions (`1.10.0` ≥ `1.4.0`).
3. **P0 fails fast; `waiting_capacity` is not built.** With no server qualifying, `reserve()` throws `DOM_E_NO_CAPACITY` (HTTP 503) with the whole explanation in `details.selection`. Parking a deployment until a server qualifies is a state of the deployment, so it belongs to the runner (step 07); `brain_website_deployments` has no such state yet.
4. **No HTTP route.** The staff dry run `POST /http/servers/select` and the infra screen are the signed surface (step 08); `dryRun()` is the service they will call. It was exercised against the real lab data (§7).
5. **Region policy is a filter that needs input.** With `DOMAINS_SELECT_REGION_POLICY=true` and `allowed_regions` in the context, servers outside are excluded; **where a member's regions come from** (plan, member, country → region via `brain_http_servers_regions`) is not specified anywhere yet, so the context is whatever the caller passes. Nothing derives a region from a country.
6. **The score's `weight` factor** is the typed `weight` (0–100, default 100) divided by 100.
7. **P2 is not built:** distance scoring (no coordinates are stored), the weights-tuning UI, draining automation.
8. **Removals (§6.7).** `createWebsite2` — unrouted (no route, no caller) and marked obsolete in its own docblock — was deleted from `members`. **`selectTenantServer()` and the server logic in `getTenantData` stay**: the live `createWebsite()` still calls them, and removing them is step 07's change (the deploy service takes the server from `reserve()`). The `…1`-suffixed selection functions and `GetPortsBlock1` in `core/scripts/background_runner.php` are **not touched**: the owner is editing `members/core/scripts` (decision D2) and they are dead code, not a risk.

## 5. What is not built

Listed in §4: disk and monitoring-driven health, `waiting_capacity`, the route and the infra screen, deriving a member's region, the removal of the old selection in `createWebsite()` and `core/scripts`, P2.

## 6. Verification

Run against the real lab MariaDB (`10.0.0.243`, `testdb_phpunit_www`); the pure tests use fixed fixtures and a fixed clock.

| Result | |
|---|---|
| `HttpServerSelectorTest` | **18 tests, 102 assertions, all passing** |
| Each filter | every code above, with its reason; reachability, preflight, agent version and region only when switched on; "nothing qualifies" is an explained nothing |
| Scoring | the emptier server wins; reservations count as load; health, region and weight move the score by their weights (differences asserted to 1e-6); unknown location and missing monitoring are neutral |
| Determinism | the same input ten times gives the same answer, and the order the servers are listed in does not matter; ties go to never-assigned, then least recently assigned, then lowest id; an idle fleet round-robins |
| Reservation | the slot is held at once, with `reserved_until`, `last_assigned_at` and a `server_selected` event holding the explanation; a server fills, the next takes over, then `DOM_E_NO_CAPACITY` reserves nothing; one live website per claim (the unique index) and a released slot can be taken again; only a claim that holds its name can get a server; expiry (even before the sweep), start and release; legacy tenants counted by address; the dry run writes nothing |
| **Real concurrency** | **eight PHP processes, each with its own database connection, reserve at the same instant against two servers with five free slots between them: exactly five win (3 + 2), three get `DOM_E_NO_CAPACITY`, no server is over capacity** |
| Migration | a new server defaults to `accepts_tenants = 0`; existing rows were derived from their role once |
| Whole Domains folder + guardrail, one run | **179 tests, 3,087 assertions** (step 02: 44, 03: 9, 04: 74, 05: 30, 06: 18, `MigrationOwnershipTest`: 4); one test is skipped by design (the opt-in lab DNS test). One run found a wrong expectation about the ORDER of an array in the concurrency test (a test defect, not a behaviour); it was fixed and that file re-run: 18 of 18 pass |

The plan asked for 20 processes against 5 slots; 8 were used so that the run does not load the machine (each process boots the framework). The locking does not depend on the number.

**Defects the tests found and that were fixed:** the reservation expiry compared against the real clock instead of the application's.

**Not verified, honestly:** selection with the optional filters on in a real fleet (nothing writes their columns yet); a deploy that actually uses the reservation (step 07); the effect of `region` with real data.

## 7. Applied to the lab

Migration `…000005` was applied to the lab database (`testdb_appfactorystudio`) after a copy of `brain_http_servers` (`brain_http_servers_backup_20261008b`). Effect on the two server rows:

| server | type | `accepts_tenants` | `max_tenants` | tenants counted |
|---|---|---|---|---|
| `server1.cradle2careertx.org` (10.0.0.243, the `brain` VM) | Brain | **0** (was 1 from step 03) | 200 | 3 (the lab tenants) |
| `node1.appfactory.studio` (10.0.0.244, the `web` VM) | Head | 1 | 200 | 0 |

`dryRun()` against this data picks **`node1` (web)** with score 0.825 and explains `brain` as "not set to accept tenants" — which is what 09 §9.1 says "create website" should do in the lab. If you want new lab tenants on `brain` as well (09 D18a), set `accepts_tenants = 1` on that row through the Brain's infrastructure write path (an operator action, not a migration).

## 8. To do next, in order

1. **Step 07** replaces `selectTenantServer()` in `createWebsite()` with `reserve()` / `start()` / `release()`, stores `selection` on the deployment, writes `health_state`, `last_heartbeat_at`, `preflight_ok_at`, `agent_version` from the tenant agent, and then switches the three optional filters on; it also owns `waiting_capacity`.
2. **Step 08** exposes `dryRun()` as the staff route and on the infra screen.
3. **Decide where a member's region comes from** (plan, profile or country) before turning region policy on.
4. **Step 12** calls `reserve()` for the move target with the same filters.
