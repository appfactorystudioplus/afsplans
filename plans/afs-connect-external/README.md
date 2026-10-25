# afs-connect external API — plan index

Plans for a **fourth coordinator auth surface**: token-based HTTP APIs for external SDK clients (Unity, mobile, CLI, third-party tools) that cannot run Checkpoint SSO in code.

**Problem:** The current public PM API (`/api/afs-connect/*`) and engine API (`/api/engine/v1/connect/*`) assume a Checkpoint browser session or a Joystick-specific Checkpoint app token. External integrators using the language SDKs in `apps/afsconnect` have no practical way to log in from code.

**Solution:** Members create **personal API tokens** in the members dashboard. External apps send **only that token** on new routes under `/api/external/afs-connect/v1/*`. Coordinator looks up the token → **`member_reference_num`** → queries **Brain** for the current member row (`user_id` is always fresh). **No client-supplied ids.**

## Documents

| # | File | Contents |
|---|------|----------|
| 00 | [00-overview.md](./00-overview.md) | Goals, non-goals, auth surfaces comparison, high-level flow |
| 01 | [01-token-management-members.md](./01-token-management-members.md) | UI, routes, token lifecycle in `apps/members` |
| 02 | [02-coordinator-external-routes.md](./02-coordinator-external-routes.md) | New route group, middleware, controllers |
| 03 | [03-database-schema.md](./03-database-schema.md) | `afsconnect_api_tokens` table and fields |
| 04 | [04-api-contracts.md](./04-api-contracts.md) | Request/response shapes for external SDK |
| 05 | [05-security.md](./05-security.md) | Hashing, scopes, rate limits, audit |
| 06 | [06-sdk-and-docs.md](./06-sdk-and-docs.md) | Changes to `apps/afsconnect` after backend lands |
| 07 | [07-implementation-phases.md](./07-implementation-phases.md) | Ordered build steps, tests, rollout |

## Related existing docs

- [afs-connect-how-it-works.md](../afs-connect-how-it-works.md) — current platform (3 auth surfaces)
- [afs-connect/04-api-contracts.md](../afs-connect/04-api-contracts.md) — existing PM + internal contracts
- [afs-connect/06-security-and-service-auth.md](../afs-connect/06-security-and-service-auth.md) — HMAC service auth (internal apps only)
- [apps/afsconnect](../../../afsconnect/README.md) — language SDKs (will switch primary auth to external token)

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) when its
work lands — source basename plus `.complete.md`. Include status, summary, files changed,
verification steps, and follow-up work.

## Status

**Phases 1–5 implemented** (2026-07-31). Coordinator token storage and external routes,
members token UI, external write ingest, and the SDK/doc rewrite are all done. **Phase 6
— rollout — has not been performed.** See
[complete/07-implementation-phases.complete.md](complete/07-implementation-phases.complete.md)
for the phase-to-document mapping and the remaining gaps.
