# 07-implementation-phases — Completion

**Completed:** 2026-07-31 (Phases 1–5)

**Status:** Partial. Phases 1 through 5 shipped. **Phase 6 — rollout — was not
performed**, and parts of the plan's testing checklist are implemented but not covered by
an automated test.

## Summary

This document defined the work as six ordered phases, while the rest of the plan folder is
organised by subject. The completion records were originally written per phase; they are
now filed per document, in `complete/`, matching the plan's own numbering. The mapping:

| Plan phase | Recorded in |
|------------|-------------|
| 1 — Coordinator foundation | [03-database-schema.complete.md](03-database-schema.complete.md) (migration, model, token service, Brain resolver) and [02-coordinator-external-routes.complete.md](02-coordinator-external-routes.complete.md) (middleware, alias, internal token CRUD) |
| 2 — External read routes | [02-coordinator-external-routes.complete.md](02-coordinator-external-routes.complete.md), contract in [04-api-contracts.complete.md](04-api-contracts.complete.md) |
| 3 — Members UI + proxy | [01-token-management-members.complete.md](01-token-management-members.complete.md) |
| 4 — External write routes | [02-coordinator-external-routes.complete.md](02-coordinator-external-routes.complete.md), contract in [04-api-contracts.complete.md](04-api-contracts.complete.md) |
| 5 — SDK + documentation | [06-sdk-and-docs.complete.md](06-sdk-and-docs.complete.md) |
| 6 — Rollout | **Not done** — see below |

Security properties are cross-cutting across phases 1, 2, and 4 and are recorded together
in [05-security.complete.md](05-security.complete.md).

`00-overview.md` has no completion document — it is framing, and the decisions it lists
were resolved inside the documents that implement them.

## Phase exits

Each phase's stated exit condition was met:

1. Members can call the internal API to create a token; a Bearer curl reaches the external
   prefix.
2. A read-only token can search and read status.
3. End to end — a user creates a token in the browser and curl search works.
4. A write-scoped token ingests snapshot and activity rows.
5. The published SDK matches the deployed coordinator surface.

## Dependencies, as they actually resolved

- **Phase 4 was blocked on snapshot/activity persistence** — the engine controllers were
  stubbed. Resolved by writing a new `ExternalSdkIngestService` for the external surface
  rather than completing the engine controllers. The engine stubs are unchanged.
- **Phase 3 required Phase 1's internal token API** — held; members has no local token
  store.
- **Phase 5 required Phase 2 at minimum** — held; the SDK shipped after the read routes.

## Testing checklist

| Check | State |
|-------|-------|
| Create token → search with Bearer only → revoke → search fails `401` | Covered in parts — `401` without a token and a successful read with a valid token are in `ExternalAfsConnectApiTest`; revoke round-trip is unit-tested in `AfsConnectApiTokenServiceTest`. Not exercised as one end-to-end sequence. |
| Activity write uses Brain-resolved `member.user_id` | Implemented via `ExternalMemberIdentityResolver`. No automated test named. |
| Token on member without afs-connect → `503` | Covered — entitlement fixture in `ExternalAfsConnectApiTest`. |
| Read token on `POST /snapshot` → `403` | Covered — `test_write_snapshot_requires_write_scope`. |
| Write token on `POST /snapshot` → `200`, index rows appear | Covered — `test_write_snapshot_with_write_scope`. |
| Max tokens enforced | Implemented in `assertUnderLimit()`. No test named. |
| Internal token API rejects unsigned callers | Enforced by the existing `afs_connect.service` HMAC middleware plus a `members`-sender check. No test specific to the token routes. |
| `last_used_at` updates within 5 minutes of a call | Implemented — written synchronously by the middleware, so effectively immediate. No test named. |

## Files changed

None. This document is the work breakdown; the code it schedules is recorded in the seven
completion documents above.

## Follow-up work

### Phase 6 — rollout (not started)

1. Deploy coordinator, migration first.
2. Deploy the members UI.
3. Announce in members release notes: "API tokens for external tools".
4. Monitor `401`/`503` rates on the external prefix.
5. Optional: Brain admin view of active token counts.

### Out of scope for the initial release, as planned

MarketController integration, OAuth2 delegated access, per-token IP restrictions, token
expiry UI, and a Brain admin force-revoke UI. All still open, all deliberate.
