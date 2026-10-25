# HTTP consolidation onto `libs/checkpoint_cpp` — completion report

**Date:** 2026-08-20
**Projects touched:** `JoystickEngine_v2` only.
**Build:** `JoystickApp` and `JoystickLauncher` both `[100%] Built target`, 0 errors.

Follow-on from the step 18 audit, which found two per-platform HTTP stacks in one process and an
engine client with no Linux backend.

---

## What changed

### `libs/checkpoint_cpp` gains a public HTTP surface

`ITransport` was already the seam every Checkpoint call went through, but its only implementation
(`DefaultTransport`) lived in `src/` and was unreachable from outside the library — which is why the
engine had its own parallel stack.

- **`include/Checkpoint/Http.h`** (new) — `CreateDefaultTransport()`, `HttpSend()`, and
  `HttpGetToMemory()` for redeeming a signed URL with no `Authorization` header (attaching a bearer
  token to a CDN-hosted archive URL would leak it).
- **`Client::Send(request, RequireAuth)`** (new) — attaches the session bearer and, on a 401,
  refreshes once and retries once. Mirrors the TypeScript client's
  `HttpClient.request(..., requireAuth)`. `RequireAuth` is an enum, not a bool, so call sites read as
  `Send(req, RequireAuth::Yes)`.
- **`Response::GetHeader()`** (new) — case-insensitive, per RFC 9110. Dream Graph sync reads
  `X-Tilemap-Version`; without this each caller would re-derive it, which is how the engine's
  now-deleted `HttpResponse::GetHeader` came to exist in parallel.

### The engine's HTTP client is deleted

Removed: `Joystick/src/Joystick/Net/HttpClient.{h,cpp}`, `Platform/MacOS/HttpClient.mm`,
`Platform/Windows/HttpClient.cpp`. Zero references remain (verified by grep).

Repointed onto the shared transport:

| Caller | Auth |
|---|---|
| `MarketClient` | Checkpoint bearer (market entitlements) |
| `DreamGraphSync` | its own project-scoped machine token — **unchanged**, see below |
| `ConnectService` | Checkpoint bearer, via `Client::Send(..., RequireAuth::Yes)` |

**This fixes the Linux link gap for all three at once.** `HttpClient.h` documented a
`Platform/Linux/HttpClient.cpp` that never existed, so `Send()` had no Linux definition and anything
calling it failed to link. `libs/checkpoint_cpp` already ships a Linux transport (a `curl`
shell-out using `fork()`+`execvp()` with an argv array rather than `popen()`, deliberately, because
request bodies carry tokens).

### `ConnectService` — two stubs that reported success

Both were `return true` with `TODO(step 61-F)`:

- **`TrySyncOne()` popped an item and discarded it.** The queue drained, status showed a successful
  sync, and coordinator received nothing — while the retry/backoff/spool machinery around it had
  nothing to act on. Now peeks, sends, and removes **only on success**, so a failed send is retried
  rather than lost.
- **`PostSnapshotToCoordinator()`** now builds the real payload and POSTs it authenticated, keyed by
  `ProjectConfig::Connect::ProjectId` (§61.4.1's coordinator-side UUID). Refuses when the project is
  unregistered rather than sending an empty path segment.
- **`CheckEntitlement()`** now calls the entitlement endpoint and **fails closed** on any non-explicit
  yes — matching `Checkpoint::Authz::IsFeatureEnabled` and coordinator's own service. Previously
  `return true`, meaning the gate did not exist: a member whose plan excludes afs-connect would still
  have project data posted on their behalf.
- Entitlement is checked **per-send, not at startup** — at editor launch the user is usually signed
  out, and a fail-closed check there would disable the service for the whole session.

---

## DreamGraphSync: deliberately NOT converted to a user token

Investigated on the premise that it was unauthenticated. **It is not.** The design is already
correct and was left alone:

```
sign in to dream_graph (Checkpoint)
   → POST /projects/{id}/tilemap-sync-tokens   [checkpoint.member + ownership check]
   → per-project machine token, abilities tilemap:read / tilemap:write
   → engine stores it in .joystick-sync
   → GET/POST /tilemaps/{id}/export|import     [middleware tilemap.sync:*]
```

`routes/api.php` calls this *"a different, already-correct auth mode"*, and
`TilemapSyncTokenController`'s docblock records that the guess-a-projectId hole was already fixed.

Converting to a Checkpoint **user** token would be a downgrade twice over:

1. Sync runs continuously in the background; a user token dies at sign-out, so sync would silently stop.
2. `SyncTokenStorage`'s own header notes `.joystick-sync` is *"trivially portable by just copying the
   project directory"* — putting a user's access token there means copying a project hands over that
   user's identity. A project-scoped, independently revocable machine token is right for that location.

**The real gap is UX and it is not built:** the token is still pasted by hand. The convergence would
be for the engine to mint it via the member-scoped route — the config already defines
`tokenIndex`/`tokenCreate`/`tokenRevoke`, all unused. **Blocked on a missing piece:** the engine
stores no dream_graph project id anywhere (verified by grep), and the mint route needs one. Adding it
means a project-settings field plus a project-picker UI — a design decision, not a mechanical change,
so it was left for an explicit call rather than invented.

---

## `ConnectService` — completed in the follow-up pass

- **`ConnectService::Initialize()` is now called**, from `EditorLayer::OnAttach`, with `Shutdown()` in `OnDetach` alongside MarketService's. Starting it does not itself begin reporting: it drains a queue `PostProjectSnapshot` fills, every send re-checks entitlement server-side, and an unregistered project is refused.
- **Spool persistence implemented** (`LoadSpool` / `AppendToSpool`, both previously `TODO(step 61-F)`). JSON Lines, appended never rewritten, so a torn final line costs one entry rather than the file. Enforces §61.4's 5 MB / 5000-item caps by dropping oldest-first — safe here because each entry is a full-state snapshot, so a newer one supersedes an older by definition.
- **`AppendToSpool` had no caller** — anything still queued when the editor closed was simply lost, which is the exact case the spool exists for. Now drained into the spool during `Shutdown()`, after the worker join so the two cannot race.
- **A bug found in my own first cut:** sending a *spooled* item removed it from memory but not from the file, so the next `LoadSpool()` would restore and re-send an already-delivered snapshot, growing the file by one duplicate per restart. Fixed by rewriting the spool (temp file + rename) whenever a spooled entry is consumed or trimmed.

## Not done / known state

- **Launcher has a third HTTP client.** `Launcher::Net::HttpClient` (GET-only, engine downloads) is
  untouched — left alone to avoid widening a live refactor. It is the obvious next consolidation.
- **Nothing here was exercised against a live coordinator** — the changes are compile- and
  review-verified. `ConnectService`'s send path in particular has never made a real request.
- The **`ProjectSnapshot` → coordinator payload shape** is written to §61.4's description; it has not
  been validated against coordinator's actual ingest endpoint, which is itself a stub
  (`EngineTaskController`, `TODO(step 61-J)`).
- **Steps 61-I (`GitProbe`) and 61-J (`TasksPanel`) remain unstarted** — commit detection and the
  in-editor task panel. 61-J is additionally blocked on coordinator's `EngineTaskController`, which
  is a stub on that side too.
