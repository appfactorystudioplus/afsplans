# 02 — Engine Tilemap Live Sync (draft)

> **Progressive plan — draft only.** Depends on:
> 1. Dream Graph desktop tilemap subsystem (Phase 5).
> 2. [01-cloud-sync.md](01-cloud-sync.md) or at minimum project linking to cloud UUID.
> 3. Optional: studio auth for minting sync tokens from desktop.

Uses **machine sync tokens** (Dream Graph API), not necessarily the studio user session.

## Protocol (canonical)

`apps/www/_docs/tilemap/08-live-sync-protocol.md`

**Pull:** `GET /api/tilemaps/{uuid}/export?since_version=N` + bearer token  
**Push:** `POST /api/tilemaps/{uuid}/import` with `base_version` + chunks

## Desktop as sync client

1. User pastes sync token from web project settings (or mints via cloud API once cloud sync exists)
2. Store in project `sync.json` — **never export in .dgpack**
3. Main-process worker: poll pull; push after local tilemap flush
4. On 409: conflict UI

Reference: `JoystickEngine_v2/plan/dream-graph-sync-system.md`

## Relationship to offline MVP

| Handoff | Offline MVP | This feature |
|---------|-------------|--------------|
| Engine import | Manual `joystick-tilemap/1` zip (Phase 6) | Optional live pull/push |
| Token storage | N/A | `sync.json` in `.dgproj` (gitignored from bundles) |

## Open decisions

- Poll interval vs SSE (web supports both patterns in places — confirm against canonical doc before implementation)
- Whether desktop mints tokens or web-only UI remains source of truth
