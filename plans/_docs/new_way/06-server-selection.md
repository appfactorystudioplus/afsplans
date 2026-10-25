# 06 — Choosing the best server

Today "best" is "the first active server of type `Head`" (F17). The least-tenants query exists but is unrouted and
ignores the server type; the geo-distance and capacity code are dead `…1` functions (F18); nothing stops two deploys
taking the last slot (F19). This step replaces all of it with one service whose decision is **filtered, scored,
reserved and explained**.

## 6.1 Where it runs

`HttpServerSelector` in the Brain (`www/app/Services/Brain/…`), called by the deploy service (07) inside the same
transaction that reserves the slot. Members never selects; it asks for a website and is told where it landed.
A staff-only dry-run (`POST /http/servers/select {claim_ref}`) returns the explanation without reserving, for the infra
screen and for tests.

## 6.2 Hard filters (a server that fails one is not a candidate)

| Filter | Source | Notes |
|---|---|---|
| `active = 1` | `brain_http_servers` | |
| `accepts_tenants = 1` and `draining = 0` | new typed columns (03 §3.7) | `draining` is the maintenance switch: existing tenants stay, nothing new lands |
| Role | `server_type` | Tenants go on `Head` servers, as `selectTenantServer` already requires. A server of type `Brain` hosts the platform and is never a candidate unless an operator sets `accepts_tenants` on it explicitly (lab2 does this for testing, 09) |
| Capacity | tenants on it + live reservations < `max_tenants` | `max_tenants` is the typed column; if null it is derived as `ram_size × 1000 ÷ tenant_size` from the server JSON — the formula the dead code already used. Reservations count (§6.4) |
| Disk | free space on the tenant volume ≥ `min_free_gb` | From the latest monitoring report if present (§6.3) |
| Agent | `agent_version ≥ required` | The tenant agent (07 §7.2) must be new enough for the release being deployed |
| Preflight | last successful `preflight` within the configured age | Node/npm on PATH, registry and Composer credentials, git reachability (07 §7.2 "Server initiation"); a server that fails it is excluded with the reason |
| Region policy | member's allowed regions | P0: no restriction. P1: the plan or the member can be limited to regions (data residency) |
| Reachability | last successful SSH/agent ping within the window | A server that cannot be reached cannot be deployed to; this is not "health", it is "can the job even start" |

If **no server passes**, the deployment does not fail: it enters `waiting_capacity`, staff are notified once, and it
resumes when a server qualifies (P1; P0 fails fast with a clear message and a retry button).

## 6.3 Score (deterministic; higher wins)

```
 score = w_load   · (1 − (tenants + reservations) / max_tenants)
       + w_health · health_factor                     1.0 healthy · 0.5 unknown · 0 degraded (degraded is also a soft exclude)
       + w_region · region_match                      1.0 same region as the member · 0.5 same country · 0 otherwise
       + w_weight · server.weight                     operator knob, 0..1, to steer traffic to new/cheap hardware
```

Weights live in `config/domains.php` (`selection.weights`), defaults `load 0.6, health 0.2, region 0.15, weight 0.05`.
Ties break by **least recently assigned**, then lowest id, so the same inputs always give the same answer and an idle
fleet round-robins instead of hot-spotting the lowest id.

**Health input.** The server-monitoring foundation (`plans/brain_flutter/14-server-monitoring-foundation.md`, built per
the project notes) already collects live load, memory and disk per server. The selector reads its latest report; **if no
report exists the factor is `unknown`, not a failure**, so selection works on a fleet or a lab with no monitoring. The
integration must be confirmed against the shipped schema (step 10 P0 check) rather than assumed from the plan.

**Region and geography.** P0 ignores geography. P1 maps the member's country to a region via
`brain_http_servers_regions`. P2 may add distance: server coordinates are **precomputed and stored on the server row**
(the dead code geolocates every server by calling MaxMind and Google per request with a `sleep(3)` between servers — that
never belongs on a request path), and the member's location is resolved once at signup.

## 6.4 Reserve, don't just pick

Inside one transaction:

1. `SELECT … FROM brain_http_servers WHERE <eligible> ORDER BY id FOR UPDATE` — the candidate rows are locked in a stable
   order (no deadlocks).
2. Compute current load from `brain_http_server_tenants` (live + reserved) **under that lock**, score, choose.
3. `INSERT` the tenant row with `state = reserved`, `reserved_until = now + 30 min`, `server_ref`. It counts toward
   capacity immediately, so a second concurrent deploy sees one slot fewer.
4. The unique `active_claim` index (03 §3.7) refuses a second live tenant for the same claim.
5. Commit; the selection record is written to `brain_website_deployments.selection`.

A reservation moves to `provisioning` when the deploy starts, `active` when it completes. A sweep (every 5 minutes)
turns expired reservations into `failed` rows that no longer count. A failed or cancelled deploy releases it immediately.

## 6.5 Explain it

`selection` (TEXT json) stores, for the chosen deploy: every server considered, which filter removed it or its score
components, the chosen server, the weights version, the time. The staff infra screen shows it; support can answer "why
is my site on server 3" without a developer. The member sees only the region/name the platform wants to expose.

## 6.6 Stickiness and moves

A claim's tenant stays where it is. Re-deploys and rollbacks never re-run selection. Moving a site is step 12: staff pick the
target, which must pass the same hard filters (§6.2) and takes a capacity reservation (§6.4).

## 6.7 What this removes

- `selectTenantServer()` and the `getTenantData` server logic in members (`DomainController.php:1169-1177`).
- The unrouted `createWebsite2` and its call to `/http/servers/available`.
- The `…1`-suffixed selection functions in `background_runner.php` (`SelectDeploymentServerForMember1`,
  `SortAvailableServers1`, `SelectServer1`, `GetPortsBlock1`, `GetDistanseBetweenServerAndMember1`). **Ports blocks**
  (`GetPortsBlock1`) belonged to a per-tenant port design the current vhost template does not use (everything is :808);
  if a feature still needs per-tenant ports it is re-specified in 07, not resurrected from dead code.

## 6.8 Testing

Isolated tests on the selector with fixtures (pure function of servers, tenants, reports): each filter; scoring order; tie
rules; capacity counting reservations; degraded exclusion; null monitoring; determinism (same input ten times).
Concurrency test: 20 simultaneous reserve calls against a server with 5 free slots → exactly 5 succeed, 15 get the next
server or `waiting_capacity`, no server over capacity. Lab: one real server (`web`), plus a second row marked
`accepts_tenants = 1` pointing at the `brain` VM, to watch spread and `draining` in practice (09).

## 6.9 Phases

| Phase | Work |
|---|---|
| **P0** | Typed columns; filters (active, accepts, draining, role, capacity with reservations, reachability); score with load + tie-break; reservation transaction; `selection` record; remove `selectTenantServer`/`createWebsite2` |
| **P1** | Monitoring input (health, disk); region policy; `waiting_capacity`; reservation sweep; staff dry-run and screen; agent-version filter |
| **P2** | Distance scoring with precomputed coordinates; weights tuning UI; draining automation |
