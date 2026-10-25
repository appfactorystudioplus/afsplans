# 02 — TURN infrastructure (Coturn, multi-server)

## 1. Source and build

Built from the clone at `C:\projects\coturn-master` (upstream [coturn/coturn](https://github.com/coturn/coturn)).
That tree is a source checkout, not a deployment — it is not part of any app repo and ships no
code of ours. This document is the configuration and fleet-topology spec for standing up
independent Coturn instances from it; the actual build/deploy scripting is implementation, not
planning, and happens when this step is executed.

Reference points inside `coturn-master` this plan is built from:
- `docker/coturn/turnserver.conf` — the base config template (annotated below).
- `docs/Configuration.md`, `README.turnserver` §"TURN REST API" — the time-limited credential
  mechanism this plan uses for authentication.
- `docker/docker-compose-redis.yml`, `docker/redis/redis.conf` — Redis as the shared stats/user
  database, used here for fleet load reporting (§4).

## 2. Why TURN is still needed with an SFU

LiveKit (`01` §1) is the media router, but LiveKit itself needs TURN for the same reason any
WebRTC endpoint does: a participant behind a symmetric NAT or a restrictive corporate firewall
cannot always reach the SFU's public IP directly over UDP/TCP. TURN is the relay of last
resort between each client and the SFU — it does not replace LiveKit, it sits underneath every
client's ICE candidate gathering, LiveKit included.

## 3. Multi-server fleet: naming and topology

Per-server DNS names, incrementing:

```
s1.conference.members.appfactory.studio
s2.conference.members.appfactory.studio
s3.conference.members.appfactory.studio
...
```

Each `sN` is an **independent Coturn process** (own machine or container, own public IP,
own `listening-port=3478` / `tls-listening-port=5349`). Nothing about Coturn itself clusters
multiple instances into one logical server — the fleet-level behavior (which server a given
session uses, how load is spread) is built in `w/communications`, not in Coturn config. This
mirrors Coturn's own documented model: `alternate-server` gives round-robin redirection *within
a single Coturn process's aux servers*, which is the wrong shape for geographically or
independently deployed boxes — so this plan does load balancing at the application layer
instead (§5), and leaves `alternate-server` unused.

## 4. Shared authentication: TURN REST API, one secret across the fleet

Every `sN` instance is configured with the same mechanism (`turnserver.conf` base, per-server
overrides only for `listening-ip`/`external-ip`/`realm`):

```
use-auth-secret
static-auth-secret=<fleet-wide shared secret, see 06-security.md>
realm=conference.members.appfactory.studio
lt-cred-mech        # implied by use-auth-secret, left explicit for clarity
fingerprint
no-cli               # cli left on localhost-only if used at all; never exposed
```

**The secret is identical on every `sN` and known only to `w/communications`.** This is a
deliberate fleet-wide choice, not a per-server secret, for one reason: a client can be handed
**two** ICE server entries (a primary `sN` plus one fallback `sM`) from a single token-minting
call, and because both servers validate the same HMAC secret, the client's existing
credential works on either without a second round trip to `w/communications`. A per-server
secret would force `w/communications` to mint and hand back N separate credentials for N
candidate servers, and would leak which specific server was chosen to any inspector of the
client's ICE config — the shared-secret design avoids both.

Credential mechanics (RFC-standard, implemented in `w/communications`, not in Coturn):

```
username = "<unix-timestamp-expiry>:<member_reference_num-or-guest-session-id>"
password = base64(HMAC-SHA1(shared_secret, username))
```

`w/communications` mints this pair at token-issuance time (`01` §3) with a short TTL (the
recording of an hours-long meeting must not run on a credential that expired at minute 24 —
`w/communications` refreshes it transparently alongside the LiveKit token refresh on
reconnection, not by extending the TURN credential's own lifetime).

## 5. Load-balancing strategy

`w/communications` owns a `members_communications_turn_servers` table (`04` §2) — the
authoritative registry of the fleet, independent of Coturn's own state:

| Column | Purpose |
|---|---|
| `server_number` | The `N` in `sN` |
| `hostname` | `sN.conference.members.appfactory.studio` |
| `region` | Free-text now (`us-east`, `eu-west`, ...); used for future geo-affinity, not v1 |
| `capacity_weight` | Relative sizing (a bigger box gets a higher weight) |
| `active` | Excluded from selection when false (maintenance, decommission) |
| `active_sessions` | Last-reported concurrent allocations, updated by §4's heartbeat |
| `last_heartbeat_at` | Staleness check — a server not heard from in N seconds is treated as `active=false` regardless of its stored flag |

**Selection algorithm at token-issuance time**, in order:

1. Filter to `active=true` and `last_heartbeat_at` within the freshness window.
2. Pick the server with the lowest `active_sessions / capacity_weight` ratio (weighted
   least-loaded, not plain round robin — a `capacity_weight=2` box legitimately takes twice the
   sessions of a `capacity_weight=1` box before either is preferred over the other).
3. Pick a second server the same way, excluding the first, as the fallback ICE candidate.
4. Return both as `iceServers` entries in the same response that carries the LiveKit token.

**Heartbeat / load reporting**: each `sN` instance is configured with Coturn's
`redis-statsdb` pointed at a Redis instance `w/communications` also reads
(`docker/docker-compose-redis.yml` in `coturn-master` is the reference compose file for that
Redis). Coturn publishes allocation/session events to Redis itself — `w/communications` runs a
small poller/subscriber that rolls those events up into `active_sessions` per server on the
interval the freshness window above assumes. This keeps load data out of band from the
HTTP request path entirely; token issuance reads the already-current `members_communications_turn_servers`
row, not Redis directly.

**Decision needed:** region-aware selection (prefer the `sN` geographically nearest the
participant) is explicitly deferred — `region` is captured now so it costs nothing to add
later, but v1 selection is capacity-only.

## 6. Adding a server

Operationally (not automated by this plan): stand up `sN`, point its DNS at
`sN.conference.members.appfactory.studio`, configure it per §4 with the shared secret, insert
its row into `members_communications_turn_servers` with `active=false`, verify Redis
heartbeats are arriving, then flip `active=true`. No client-side or `w/communications`
deploy is needed to add capacity — this is the entire point of driving selection from a table
rather than a hardcoded list.
