# 06-sdk-and-docs — Completion

**Completed:** 2026-07-31

**Status:** Complete. All six language packages expose an external client, and the README
and docs lead with token-based integration. The legacy Checkpoint clients were kept and
re-documented rather than removed, as the plan directs.

## Summary

### SDK surface

Every language in `apps/afsconnect` gained a client targeting
`/api/external/afs-connect/v1/*` with Bearer token auth:

| Language | Class | File |
|----------|-------|------|
| TypeScript | `ExternalClient` | `afsconnect/code/typescript/src/external-client.ts` |
| PHP | `ExternalClient` | `afsconnect/code/php/src/ExternalClient.php` |
| C# | `AfsConnectExternalClient` | `afsconnect/code/csharp/AfsConnectClient.cs` |
| Java | `AfsConnectClients.ExternalClient` | `afsconnect/code/java/.../AfsConnectClients.java` |
| Kotlin | `AfsConnect.ExternalClient` | `afsconnect/code/kotlin/.../AfsConnectClient.kt` |
| Swift | `AfsConnect.ExternalClient` | `afsconnect/code/swift/.../AfsConnectClient.swift` |

The C# name differs by the plan's own allowance — "same class name pattern per language
conventions".

Methods, identical across all six: `getStatus`, `getSummary`, `search`, `getActivity`,
`getInsights`, `postSnapshot`, `postActivity`.

Config is the two fields the contract permits — `coordinatorUrl` and `apiToken`. No
`memberUserId`, no `checkpointUserId`, no `pmSession`.

### Documentation

| File | Change |
|------|--------|
| `afsconnect/README.md` | Token-first quick start, env vars, `ExternalClient` examples |
| `afsconnect/docs/overview.md` | External API as the primary surface; legacy Checkpoint clients documented below it |
| `afsconnect/docs/registration.md` | Token creation steps; Checkpoint marked legacy |
| `afsserver/plans/afs-connect-how-it-works.md` | §2 external API table (`afs-connect-how-it-works.md:66`) plus a doc-map link |

Env vars documented for users:

```env
AFS_CONNECT_COORDINATOR_URL=https://coordinator.example.com
AFS_CONNECT_API_TOKEN=afc_live_...
```

Token creation path in the docs: **Members dashboard → Settings → AFS Connect → API
access**.

### Legacy clients

`PmClient`, `EngineClient`, and `CoordinatorClient` remain in place for
Checkpoint-embedded apps — coordinator's own web parity, Joystick, and the internal
monorepo ingest path respectively. External integrators are pointed at `ExternalClient`.
The external token API is presented as the first stable external integration path, since
the Checkpoint-header approach was never viable for external apps.

## Files changed

- `afsconnect/code/typescript/src/external-client.ts` — new.
- `afsconnect/code/php/src/ExternalClient.php` — new.
- `afsconnect/code/csharp/AfsConnectClient.cs` — `AfsConnectExternalClient` added.
- `afsconnect/code/java/src/main/java/com/appfactory/afsconnect/AfsConnectClients.java` —
  `ExternalClient` added.
- `afsconnect/code/kotlin/src/main/kotlin/com/appfactory/afsconnect/AfsConnectClient.kt` —
  `ExternalClient` added.
- `afsconnect/code/swift/Sources/AfsConnect/AfsConnectClient.swift` — `ExternalClient`
  added.
- `afsconnect/README.md`, `afsconnect/docs/overview.md`,
  `afsconnect/docs/registration.md` — rewritten to lead with the token flow.
- `afsserver/plans/afs-connect-how-it-works.md` — external API section.

## Verification steps

Against a staging host, per the plan's own procedure:

1. Enable afs-connect on a test member.
2. Create a read token in members settings.
3. Confirm the raw route answers:

```bash
curl -H "Authorization: Bearer afc_live_..." "https://coordinator.../api/external/afs-connect/v1/status"
```

4. Run the SDK integration suite against the same host.

## Follow-up work

- **Per-language docs** — the plan's doc table includes "per-language docs: replace
  Checkpoint examples". The top-level README and the two shared docs were rewritten; the
  per-language pages were not swept individually.
- **`EXTERNAL_API_PREFIX` constant** — the plan names a shared path constant; each client
  carries the prefix rather than importing one definition.
