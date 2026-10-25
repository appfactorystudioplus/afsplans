# 06 — SDK and documentation updates

After coordinator + members backend ships, update `apps/afsconnect` — **do not start until external routes exist**.

## Primary auth model change

| Before (current docs) | After |
|----------------------|-------|
| `PmClient` + `memberUserId` + `checkpointUserId` headers | `ExternalClient` + Bearer token only |
| `EngineClient` + Checkpoint access token | `ExternalClient.postSnapshot/postActivity` with write-scoped token |
| `CoordinatorClient` with `pmSession` object | **`ExternalClient` — `coordinatorUrl` + `apiToken` only** |

Keep existing clients documented as:

- **Coordinator web parity** — `PmClient` (internal reference only)
- **Joystick** — `EngineClient` (Joystick-specific)
- **Appfactory monorepo** — `IngestClient` (internal)

## New SDK surface (all languages)

```typescript
// TypeScript example
const client = new ExternalClient({
  coordinatorUrl: 'https://coordinator.example.com',
  apiToken: process.env.AFS_CONNECT_API_TOKEN!,
});

await client.search('release notes');
await client.postActivity([{ event_type: 'build_finished', ... }]); // needs write scope
```

C# / PHP / Java / Kotlin / Swift: same class name pattern per language conventions (`AfsConnectExternalClient`, etc.).

## Config env vars (document for users)

```env
AFS_CONNECT_COORDINATOR_URL=https://coordinator.example.com
AFS_CONNECT_API_TOKEN=afc_live_...
```

Token created in: **Members dashboard → Settings → AFS Connect → API access**

## Docs to rewrite

| File | Change |
|------|--------|
| `apps/afsconnect/README.md` | Lead with token + external routes |
| `apps/afsconnect/docs/overview.md` | Fourth auth surface diagram |
| `apps/afsconnect/docs/registration.md` | Token creation steps, not Checkpoint SSO |
| Per-language docs | Replace Checkpoint examples |
| `afsserver/plans/afs-connect-how-it-works.md` | Add § on external API + link here |

## Path constant

```
EXTERNAL_API_PREFIX = '/api/external/afs-connect/v1'
```

## Breaking change communication

Existing SDK consumers using Checkpoint headers were never fully viable for external apps. Treat external token API as the **first stable** external integration path.

## Testing SDK against staging

1. Enable afs-connect on test member
2. Create read token in members settings
3. `curl -H "Authorization: Bearer ..." https://coordinator.../api/external/afs-connect/v1/status`
4. Run SDK integration test suite against same host
