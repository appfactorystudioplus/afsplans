# 07 — Implementation phases

Ordered work breakdown. **No coding in this plan folder** — execute in app repos when approved.

## Phase 1 — Coordinator foundation

1. Migration: `afsconnect_api_tokens`
2. Model: `AfsConnectApiToken`
3. Service: `AfsConnectApiTokenService` (create, revoke, lookup by hash, touch last_used)
4. Brain resolver helper: `member_reference_num` → `member.user_id` via `FunctionsController::getMemberByReference()`
5. Middleware: `AfsConnectExternalTokenMiddleware` + register alias
6. Internal controller: token CRUD for members (`/api/internal/afs-connect/v1/tokens`)
7. Unit tests: token hash, scope check, revoked token rejected, Brain user id resolution

**Exit:** Members can call internal API to create a token; manual curl with Bearer works on a stub `GET /external/.../status`.

## Phase 2 — External read routes

1. External controllers for: status, summary, search, activity, insights, items, links
2. Trait `ResolvesExternalTokenMember` + entitlement trait reuse
3. Rate limiting on route group
4. Feature tests: valid token, invalid token, not entitled member
5. Update API fallback route list

**Exit:** Read-only SDK can search and read status with token.

## Phase 3 — Members UI + proxy

1. HTML subsection in settings.html
2. `afsConnectSettings.ts` — list/create/revoke
3. Members routes + controller methods proxying to coordinator internal API
4. Feature test: create token returns plaintext once; list hides secret
5. Register `external_sdk` in coordinator source registry + Brain admin visibility

**Exit:** End-to-end: user creates token in browser, curl search works.

## Phase 4 — External write routes

1. Implement snapshot persistence (complete engine snapshot TODO or shared service)
2. Implement activity persistence (complete engine activity TODO or shared service)
3. External `POST /snapshot` and `POST /activity` with write scope gate
4. Tests: read-only token gets 403 on write; write token ingests rows

**Exit:** Unity/CLI can push activity with write-scoped token.

## Phase 5 — SDK + documentation

1. Add `ExternalClient` to all languages in `apps/afsconnect`
2. Rewrite README and docs
3. Deprecate Checkpoint-as-primary in external SDK docs
4. Update `afs-connect-how-it-works.md`

**Exit:** Published SDK matches deployed coordinator.

## Phase 6 — Rollout

1. Deploy coordinator (migration first)
2. Deploy members UI
3. Announce in members release notes: “API tokens for external tools”
4. Monitor 401/503 rates on external prefix
5. Optional: Brain admin view of active token counts

## Testing checklist

- [ ] Create token → search with Bearer only (no user ids) → revoke → search fails 401
- [ ] Activity write uses Brain-resolved `member.user_id`, not a value stored on token
- [ ] Token on member without afs-connect → 503
- [ ] Read token on POST snapshot → 403
- [ ] Write token on POST snapshot → 200 and index rows appear
- [ ] Max tokens enforced
- [ ] Internal token API rejects unsigned callers
- [ ] `last_used_at` updates within 5 minutes of call

## Estimated scope

| Phase | Apps | Rough size |
|-------|------|------------|
| 1 | coordinator | Small |
| 2 | coordinator | Medium |
| 3 | members + coordinator | Medium |
| 4 | coordinator | Medium (depends on snapshot/activity completion) |
| 5 | afsconnect + docs | Medium |
| 6 | ops | Small |

## Dependencies

- Phase 4 blocked on snapshot/activity actually writing to DB (engine controllers currently stubbed)
- Phase 3 requires Phase 1 internal token API
- SDK Phase 5 requires Phase 2 minimum (read routes)

## Out of scope for initial release

- MarketController integration
- OAuth2 delegated access
- Per-token IP restrictions
- Token expiry UI
- Brain admin force-revoke UI
