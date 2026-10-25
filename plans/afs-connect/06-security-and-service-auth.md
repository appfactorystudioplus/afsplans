# 06 — Security & Service-to-Service Auth

## Two distinct trust boundaries

The existing apps have one trust boundary today: **browser → its own
backend**, where the frontend has already completed Checkpoint SSO and the
backend trusts headers like `X-Checkpoint-User-Id` / `X-User-Id` /
`X-Coordinator-Role` set by the authenticated client
(`ResolvesCoordinatorAuth`, `ResolvesMember` — see
[01-current-state-audit.md](01-current-state-audit.md) §3). That pattern is
appropriate there because the request never leaves the member's own
authenticated session.

afs-connect introduces a **second** boundary — **app → app**, server-side,
with no browser in the loop (coordinator calling notes, or a source app
webhooking coordinator). This boundary must not reuse header-trust: any
service on the shared network could otherwise forge `X-Checkpoint-User-Id`
and read another member's data. It needs its own authentication.

## Service-to-service authentication: signed requests

Reuses the naming and shape of the `system_key` concept already present in
`apps/auth/config/checkpoint.php` ("a shared secret used for server-to-server
authentication between the application servers and the central auth
server"), extended to the afs-connect endpoints specifically.

**Per-environment shared secret**, one per calling/receiving pair, stored
as env vars (never in git):

```
AFS_CONNECT_SERVICE_KEY_COORDINATOR=...   # coordinator uses this to sign outbound calls
AFS_CONNECT_SERVICE_KEY_NOTES=...         # notes uses this to sign its webhooks + verify inbound calls
AFS_CONNECT_SERVICE_KEY_DREAM_GRAPH=...
AFS_CONNECT_SERVICE_KEY_FLOW=...
AFS_CONNECT_SERVICE_KEY_PDF_EDITOR=...
```

Every app holds its **own** key (to sign what it sends) and the **public
registry** of the other apps' keys it needs to verify (mirrors how OAuth
client credentials work — a small `config/afs_connect.php` per app listing
`{app_name: key}` pairs, sourced from env). Rotate by issuing a new key,
deploying it to both sides, and revoking the old one — no shared single
secret across all five apps, so a compromise of one app's key doesn't
compromise the others.

**Request signing (HMAC-SHA256, request-bound, replay-resistant):**

```
X-AfsConnect-App: notes
X-AfsConnect-Timestamp: 1753120000
X-AfsConnect-Signature: hex(hmac_sha256(key, "{timestamp}.{method}.{path}.{sha256(body)}"))
```

Receiving middleware (`AfsConnectServiceAuthMiddleware`, added to every
internal endpoint on every app):

1. Reject if `X-AfsConnect-Timestamp` is more than 5 minutes old or in the
   future (clock-skew tolerance) — bounds replay window.
2. Recompute the HMAC using the sender's known key (looked up by
   `X-AfsConnect-App`) and constant-time-compare.
3. Reject with `401` on any mismatch; log the app name + IP for anomaly
   monitoring, never log the key or signature itself.

This is intentionally simple (no mTLS, no OAuth client-credentials flow) to
match the operational maturity of the rest of the platform (Checkpoint's own
`system_key` is a comparably simple shared-secret model) while still
preventing the two realistic threats: an unrelated service on the network
calling these endpoints, and a captured request being replayed later.

## Defense in depth: every source app re-derives entitlement itself

Coordinator's `AfsConnectEntitlementService` is the primary gate on the
coordinator side, but **each source app also runs its own
`AfsConnectEntitlementGuard`** before returning data from `summary`,
`changes`, `items`, or `search` (§4.1). It independently checks:

1. Global flag (`GET /checkpoint/authz/features/afs_connect` — same call
   coordinator makes, cached the same way).
2. That `member_reference_num` in the request actually resolves to a real,
   active member (defends against a forged/stale reference number even
   from a validly-signed request).
3. Its own `source_{app}` toggle state — fetched via the same
   `GET /api/internal/afs-connect/v1/settings` call coordinator's settings
   endpoint serves (§4.2), cached 60s.

If coordinator's cache is stale (e.g. a member just disabled afs-connect
and coordinator hasn't re-checked yet), the source app's independent,
separately-cached check still converges within its own short TTL — no
window longer than ~60s where a fully-disabled member's data is servable,
and the specific request that matters most (the live `items/{id}` fetch,
which returns actual content rather than metadata) always re-authorizes
per-user, per-item, synchronously, with no cache at all.

## Data minimization

- `afsconnect_index` (coordinator's local cache) stores **metadata and short
  snippets only** — never full note bodies, full PDF text, full graph
  definitions, or file bytes. This bounds the blast radius of a coordinator
  DB compromise: the worst case is titles/snippets/counts across apps, not
  full content.
- Full content is only ever served by the `items/{id}` live-fetch path,
  which runs the source app's real, per-item authorization on every call
  and is never cached server-side beyond normal HTTP response caching
  disabled (`Cache-Control: no-store`) for that endpoint.
- Webhook payloads (§4.3) follow the same rule — `snippet` fields are
  explicitly documented per source app as "short preview, not full
  content" (e.g. notes truncates to ~200 chars server-side before sending,
  it does not rely on coordinator to truncate).

## Team & permission scoping

**Correction:** an earlier draft of this section assumed all four source
apps already enforce `pm_team_members`-based scoping the way coordinator
does. A closer audit of each app's actual controllers
([01-current-state-audit.md](01-current-state-audit.md) §8.3) found that's
only true for `coordinator` and `flow` today. `notes` has no team/per-item
ACL at all (member-wide access), and `dream_graph`'s controller has
authorization disabled outright. afs-connect cannot assume uniform team
scoping until [09-rollout-plan.md](09-rollout-plan.md) Phase 0 item 7 (the
header-trust → real `authz.ts`-backed authorization migration) lands. The
design below is the **target state**, not something afs-connect can rely
on from day one — treat it as a hard dependency, not a detail to revisit
later.

afs-connect must not let a member's data leak across teams that are
supposed to be isolated from each other within that member's own account
(e.g. an agency member with multiple client teams in coordinator). Once
Phase 0 item 7 lands, this reuses existing structures rather than
inventing new ones:

- Every ingested/fetched item carries `pm_team_client_id` where the source
  app can determine it (via the shared `pm_teams`/`pm_team_members`/
  `pm_users` tables the other apps already read — see
  [01-current-state-audit.md](01-current-state-audit.md) §2). Where a
  source app genuinely has no team concept for an item (e.g. a personal
  note not assigned to any team), `pm_team_client_id` is null and the item
  is visible member-account-wide — but this must be an intentional product
  decision for that app (§8.4), not the accidental byproduct of a missing
  check.
- Coordinator's public API (`/api/afs-connect/*`) filters `afsconnect_index`
  and `afsconnect_links` by the requesting user's team memberships
  (`pm_team_members`) exactly the way coordinator's existing PM endpoints
  do — afs-connect does not introduce a separate authorization model,
  it plugs into the existing one.
- Secondary users (Checkpoint multi-tenant sub-accounts) get whatever
  subset of cross-app data their existing team/project memberships would
  already expose them to inside coordinator — no special-casing. This is
  now backed by a working per-app role/permission resolution
  ([01-current-state-audit.md](01-current-state-audit.md) §8.4), so a
  secondary user's afs-connect access can correctly differ from the
  primary member's, once each source app actually enforces it (item 7).

## Rate limiting & abuse

- Internal endpoints (§4.1, §4.2) are rate-limited per calling app (not per
  IP, since all calls originate from a small, known set of app servers) —
  e.g. 600 req/min per `X-AfsConnect-App` value, generous enough for
  reconciliation sweeps, tight enough to catch a runaway loop.
- The public search endpoint (`GET /api/afs-connect/search`) is rate-limited
  per authenticated user (standard Laravel throttle), since it's reachable
  from a browser session.

## Secrets & config location

**Correction:** an earlier draft of this document said service keys would
be "deployed via the same mechanism `apps/afsserver` already uses." That's
wrong — `apps/afsserver` is a server/ops tooling project (deployment
scripts, DNS, docker) and has no relationship to any of the five apps'
runtime configuration. These are ordinary Laravel apps: each one reads its
own config exclusively from its own `.env` file (`config/*.php` files just
wrap `env()` calls, per the standard Laravel pattern already used
throughout, e.g. `apps/auth/config/checkpoint.php`).

So: `AFS_CONNECT_SERVICE_KEY_*` and **`BRAIN_API_URL`** are plain entries in each app's own `.env`
(never committed — `.env.example` gets placeholder lines, actual values
are set per environment by whoever provisions that app's server), exactly
like every other secret these apps already have (`JWT_SECRET`,
`CHECKPOINT_SYSTEM_KEY`, `SENDGRID_API_KEY`, etc.). `apps/afsserver` is not
involved in generating, storing, or rotating them.

**Brain URL:** always `BRAIN_API_URL` — never a separate hardcoded host or
afsserver-managed config. For afs-connect-www, coordinator derives the www
internal API path from the same variable ([06-environment-config.md](../afs-connect-www/06-environment-config.md)).
