# 07-unified-sync-orchestration — Completion

**Completed:** 2026-08-01 (phases S0.5 and S0.5g only)

**Status:** **Partial.** This document defines the whole phase order for the plan. Only the
server-side auth prerequisites it schedules — **S0.5** and **S0.5g** — have been built. No
sync phase has started: S1 onward (S2 Coordinator PM sync, S3 Flow, S4 Dream Graph, S5 hub
reporters, S6 engine live sync) is all untouched, and remains blocked on desktop auth
(C1), which is tracked in
[06-studio-authentication.complete.md](06-studio-authentication.complete.md).

## Summary

Both completed phases came out of a code-review pass on this plan's own documents. The
plan's `authorizedFetch` sketch assumed the server would reject a bad bearer; it could not,
because nothing checked one. Member scope was resolved from a client-supplied `X-User-Id`
header via `Concerns\ResolvesMember`, so any caller could set that header to any value and
read or write another member's data.

---

## Phase S0.5 — server-side bearer enforcement (Coordinator, Flow)

Targets `apps/coordinator` and `apps/flow`.

### Shared validator (Coordinator)

| File | Change |
|------|--------|
| `coordinator/app/Services/Checkpoint/CheckpointIdentity.php` | New; generalized from the engine-only `AfsConnect\Engine\CheckpointEngineIdentity` |
| `coordinator/app/Services/Checkpoint/CheckpointTokenValidator.php` | New; same generalization, and **fixed a cache-key bug** — the original cached on `hash(token)` alone, ignoring `$requiredAppName`, so once a second caller (PM) existed, a token validated for one app returned cached-valid for another. Key is now `hash(requiredAppName . '|' . token)` |
| `coordinator/app/Services/AfsConnect/Engine/CheckpointTokenValidator.php`, `CheckpointEngineIdentity.php` | Deleted — moved to the shared location |
| `coordinator/app/Http/Middleware/EngineCheckpointAuthMiddleware.php` | `use` import updated; behaviour unchanged |

### Coordinator — `/api/pm/*` and `/api/afs-connect/*`

| File | Change |
|------|--------|
| `MemberCheckpointAuthMiddleware.php` | New. Validates the bearer against required app `COORDINATOR`; sets `checkpoint_member_reference_num`, `checkpoint_user_id`, `checkpoint_user_type`, `checkpoint_app_role`. Both primary and secondary users are valid callers — Coordinator PM has a real team/role model, so secondary tokens are **not** rejected |
| `bootstrap/app.php` | Alias `checkpoint.member` |
| `routes/api.php` | `pm` and `afs-connect` groups wrapped in the middleware — **except** `GET /api/afs-connect/sources` (see regression below) |
| `Concerns/ResolvesMember.php` | Reads the request attribute instead of the `X-User-Id` header / `?user_id` fallback |
| `Concerns/ResolvesCoordinatorAuth.php` | Reads attributes instead of `X-Checkpoint-User-Id` / `X-Coordinator-Role` |
| `PmMemberController.php` | **Separate bug, same pass:** `GET /api/pm/member` took an arbitrary `?user_id=` with no auth and returned that member's full Brain record — an unauthenticated IDOR, not merely a spoofable identity. Now resolves the caller's own record from the validated bearer, looked up by `reference_num` (not `user_id`, which does not resolve for secondary callers) |
| `AfsConnectStatusController.php` | Removed a second, parallel unauthenticated fallback (`X-Checkpoint-User-Id` → `resolveMemberReferenceNum()`) that bypassed the trait fix entirely |
| `AfsConnectLinksController.php` | `created_by_checkpoint_user_id` now comes from the validated attribute, not the header |
| `AfsConnectStatusPayloadBuilder.php` | New; status assembly extracted so it can be shared with the signed internal endpoint below |

### Regression caught and fixed — `apps/members` cross-app calls

Gating the routes broke, or would have broken, two server-to-server integrations:

1. **`GET /api/afs-connect/sources`** is a static, member-agnostic catalog
   (`SourceRegistry::publicProjection()`) consumed unauthenticated by members'
   `AfsConnectSettingsController::sources()`. Gating it would have broken the integration
   for no security benefit — the response carries no member data. Left as its own ungated
   route.
2. **`GET /api/afs-connect/status`** was called unauthenticated, server-to-server, with
   `X-User-Id` — `members` has no per-member bearer for a backend-to-backend hop. Rather
   than leave it open or break it, a signed equivalent was added:
   `Api/Internal/AfsConnectSettingsController::status()` at
   `GET /internal/afs-connect/v1/status`, behind the `afs_connect.service` HMAC middleware
   members already used, plus `CoordinatorUrl::statusAbsoluteUrl()` /
   `statusSignPath()`, `CoordinatorSettingsClient::fetchStatus()`, and a rewritten
   `fetchCoordinatorStatus()` on the members side.

### Coordinator web client

`checkpoint-auth-headers.ts` is new — a shared `checkpointAuthHeaders()` plus
`NotAuthenticatedError`, replacing ad-hoc builders duplicated across two files. `pm-api.ts`
and `afs-connect-api.ts` now send `Authorization: Bearer` and drop `X-User-Id`,
`X-Checkpoint-User-Id`, `X-Coordinator-Role`, and `?user_id=` entirely.
`member-context.ts` sends the bearer; `authz.ts` lost `getCoordinatorRoleForApi()` as dead
code.

### Flow

`CheckpointIdentity.php` and `CheckpointTokenValidator.php` ported from Coordinator — Flow
is a separate Laravel deploy with no shared PHP package to depend on.
`FlowCheckpointAuthMiddleware.php` requires app `FLOW_EDITOR` and sets the same four
attributes; `config/afs_connect.php` gained `checkpoint_base_url` reusing the existing
`CHECKPOINT_BASE_URL` env var. Both the `flow` group **and** Flow's own `pm` group (team
settings, which had the same header-trust bug via `X-App-Role`) are gated, with both
`Concerns` traits rewritten.

On the client, `projectStore.ts` previously sent the bearer only `if (token)`, silently
falling back to header-only identity — it now throws client-side when signed out rather
than sending an unauthenticated request. `settings/pm-api.ts` got the same treatment as
Coordinator's, and `settings/authz.ts` lost its dead `getAppRoleForApi()`.

### Correction made during implementation

An earlier draft of docs 06/07 specified a fifth enforcement point — "server-side rejection
of `user_type === 'secondary'`, required." That was wrong and was **not** implemented as
written. Coordinator's PM workspace and Flow's settings both have real secondary/team-member
features; blanket-rejecting secondary tokens at the middleware would have broken them for a
policy that belongs in the desktop app's own login flow. "Desktop is primary-only" is
enforced by desktop never completing a secondary login, not by the server guessing which
platform sent a request it cannot distinguish. What shipped instead: `userType` and
`appRole` are resolved server-side and exposed as request attributes for endpoints that
genuinely key off them — `PmWorkspaceController::isCoordinatorAdmin()` already does.

---

## Phase S0.5g — extended to Notes Pro, Dream Graph, PDF Editor

A routine "who else calls the endpoint we just gated" check while closing out S0.5 found
the identical bug in two more apps and a worse one in a third. None were among the plan's
original four. Fixed under the same no-gaps mandate, with explicit sign-off before each
escalation in scope.

### What was found

| App | Surface | Severity |
|-----|---------|----------|
| **Notes Pro** | `/api/pm/*` and the whole `/api/notes-pro/*` product API | Same header-trust pattern as pre-S0.5 Coordinator/Flow |
| **PDF Editor** | `/api/pm/*`, `/api/documents/*`, `/api/documents/{doc}/shares/*` | Same pattern, via a `user_id` request field rather than a header |
| **Dream Graph** | `/api/pm/*` and essentially the whole app — projects, graphs, nodes, connections, assets, entities, variables, templates, exports, imports, tilesets, tilemaps, layers, sync tokens | **Worse than header-trust.** Most controllers carried a literal `// Authorization disabled for development` comment and checked nothing at all. `ProjectController::show()/update()/destroy()` took any `{project}` by route-model binding with zero ownership check. A `GraphProjectPolicy` existed and looked like it covered this, but compared `$user->id === $project->owner_id` using session-based `Auth::user()` — a model this app does not use (it scopes by Checkpoint `member_reference_num`; `owner_id` is hardcoded `null`). Dead scaffolding from an earlier, incompatible approach; deleted rather than left to mislead |

### Delivered

The validator pair was ported into each of `notes`, `dream_graph`, and `pdf_editor` — the
same file, three more copies, since these deploys share no PHP package. All three added
`checkpoint_base_url` to `config/afs_connect.php`.

**Notes Pro** — `NotesCheckpointAuthMiddleware` (required app `NOTES_EDITOR`), both `pm`
and `notes-pro` route groups gated, both `Concerns` traits rewritten, and the client
(`settings/pm-api.ts`, `storage/remoteStore.ts`) switched to the bearer. Every `notes-pro`
controller already scoped its queries correctly by `member_reference_num` once given a real
value, so no per-controller ownership work was needed.

**Dream Graph** — `DreamGraphCheckpointAuthMiddleware` (required app `GRAPH_EDITOR`), with
nearly the whole route file wrapped in one group, excluding `dummy/ping`,
`internal/afs-connect/v1/*` (already HMAC), the tilemap.sync engine bridge (a different
intentional machine-token mode), the signed `exports/{export}/download`, and asset
image/texture serving (see the gap below).

The substantive addition is `Concerns/AuthorizesProjectOwnership.php` — real per-resource
checks, with `requireProjectOwner()` plus one `require*Owner()` helper per model, each
walking at most one relationship hop back to `GraphProject` and comparing
`member_reference_num` against the validated bearer, returning 404 on mismatch to match the
app's convention of not confirming a resource's existence to a non-owner. It was wired into
`ProjectController`, `GraphController`, `NodeController`, `ConnectionController`,
`AssetController`, `EntityController`, `VariableController`, `TemplateController`,
`TilesetController`, `TilemapController`, `TilemapImportController`,
`TilemapLayerController`, `TextController`, `StatsController`, `ExportController`,
`ImportController`, and `TilemapSyncTokenController`.

Three of those deserve calling out: `ImportController` with `mode=restore` previously let
any authenticated caller overwrite another member's project by supplying its uuid;
`TilemapSyncTokenController` was mintable for any project by anyone, unauthenticated, and
it issues the long-lived machine tokens the sync bridge accepts — arguably the most severe
single fix in the app; and `VariableController`'s `updateVariable`/`destroyVariable` routes
could never have bound their `{project}` parameter at all, a pre-existing bug unrelated to
auth, fixed in passing.

`GraphProjectPolicy` was deleted and its `Gate::policy()` registration removed.
`AssetControllerEnhanced.php` and `AssetFolderController.php` still carry "Authorization
disabled" comments but appear in no route file — confirmed dead and left alone rather than
hardening unreachable code.

**PDF Editor** — document sharing has a genuine anonymous path (public share-link tokens),
so a blanket required-bearer gate would have broken it. Two middlewares instead:
`PdfCheckpointAuthMiddleware` (required) guards `/api/pm/*`, `/api/documents`
index/store/destroy, the shares routes, and `/api/shared-with-me`;
`PdfCheckpointOptionalAuthMiddleware` validates a bearer if presented and rejects an
invalid one, but lets a request through with no identity when none is presented, so
`DocumentController::resolveAccess()`'s owner-or-shared-or-token logic keeps working for
anonymous visitors — it guards `update`, `file`, `versions`, `versionFile`,
`restoreVersion`. `GET /api/shared/{token}` stays fully public by design.

Client-side, `checkpointAuthHeaders.ts` is new (`withAuthHeader()` throws when signed out,
`withOptionalAuthHeader()` does not), `dashboard.ts` replaced its `withUserId()` query
helper across four call sites, and `editor.ts` moved to `?token=`-only for the anonymous
path. One call — the version-history Download button — used `window.open(url)`, which
cannot carry a header; it was rewritten as fetch + blob + a synthetic `<a download>` click.

### Cross-app caller check

`members`, `coordinator`, `flow`, and `www` were searched for calls into the three apps'
newly gated routes. No matches — unlike Coordinator's `/sources` and `/status`, nothing
else in the monorepo calls these APIs server-to-server, so no signed-channel follow-up was
needed.

---

## Files changed

Seven repositories were touched. The per-file tables under each phase above are the
authoritative record; in outline:

- **coordinator** — new `Services/Checkpoint/` validator pair (with the engine-only
  originals deleted), `MemberCheckpointAuthMiddleware`, `AfsConnectStatusPayloadBuilder`,
  a new signed internal `status()` action, both `Concerns` traits rewritten, four
  controllers fixed, route and alias registration, and five web-client modules.
- **flow** — ported validator pair, `FlowCheckpointAuthMiddleware`, config, routes, both
  traits, and three web-client modules.
- **notes**, **dream_graph**, **pdf_editor** — a ported validator pair and config each;
  per-app middleware (`NotesCheckpointAuthMiddleware`, `DreamGraphCheckpointAuthMiddleware`,
  and PDF Editor's required/optional pair); routes and aliases; both traits per app; and
  their web clients. Dream Graph additionally gained
  `Concerns/AuthorizesProjectOwnership.php` wired into seventeen controllers, and lost
  `Policies/GraphProjectPolicy.php` and its `Gate::policy()` registration.
- **members** — `CoordinatorUrl`, `CoordinatorSettingsClient`, and
  `AfsConnectSettingsController` moved onto the signed status channel.

## Exit criteria

All met, across both phases:

- Forged-identity `curl` against Coordinator `/api/pm/*` and `/api/afs-connect/*` (except
  `/sources`), Flow `/api/flow/*` and its `/api/pm/*`, Notes `/api/pm/*` and
  `/api/notes-pro/*`, Dream Graph `/api/pm/*` and project-scoped routes, and PDF Editor
  `/api/pm/*` and document routes — **401** in every case.
- Valid primary bearer succeeds, scoped to its own member; valid secondary bearer succeeds,
  scoped to its own member and role.
- `GET /api/afs-connect/sources` still works unauthenticated, by design.
- The members ↔ coordinator settings/status integration still works, now over the signed
  channel.
- `GET /api/pm/member` no longer returns arbitrary members' data.
- A token validated for one required app is not accepted for another (the cache-key fix).
- Dream Graph: member A cannot reach member B's project by id guessing; `mode=restore`
  cannot overwrite another member's project; sync tokens cannot be minted for another
  member's project.
- PDF Editor: anonymous share-link access still works, and `GET /api/shared/{token}` is
  untouched.

## Verification steps

```bash
php -l <file>    # every touched PHP file, individually and swept by mtime
tsc --noEmit --skipLibCheck --target es2020 --module esnext --moduleResolution bundler --strict <file>
```

No syntax errors in any touched PHP file, and no new TypeScript errors — pre-existing
unrelated ones (missing jQuery declarations, `import.meta.env`, Vite `?raw`/`?worker`
import suffixes) were confirmed present beforehand and left alone. TypeScript was checked
per-file with a global `tsc`, since none of these apps had `node_modules` installed in this
environment.

**No manual or browser testing was performed** — no running instance of any app was
available. The exit criteria above are verified by code inspection (route middleware
registration, request-attribute plumbing, trait rewrites) and static checks only.

## Known, deliberate, not-fixed gap

**Dream Graph asset image/texture serving** (`GET /api/assets/{uuid}` and
`/api/assets/{uuid}/thumbnail`) stays unauthenticated. At least eight frontend call sites
load these through plain `<img src>` or unauthenticated `fetch()` — across
`graph/model/nodeTypes/image.tsx`, `graph/panels/AssetPanel.tsx`,
`graph/panels/AssetPicker.tsx`, `graph/model/nodeTypes/tilemapRef.tsx`,
`graph/playMode/PresentationMode.tsx`, `tilemap/panels/NewTilesetDialog.tsx`, and
`tilemap/surface/tileset.ts` — and none can attach an `Authorization` header. Gating these
two routes would 403 every image and texture in the app rather than close a gap. The real
fix is signed URLs, the pattern this app already uses for `exports/{export}/download`,
minted server-side and threaded through all eight call sites — a frontend-architecture
change, out of scope for this pass.

## Follow-up work

- **Every sync phase in this document is unstarted** — S1 through S6. None may ship until
  desktop auth (C1) lands.
- **`POST /api/pm/projects`** — doc 03's linking flow currently routes project creation
  through `PUT /api/pm/workspace`'s upsert semantics, with a read-modify-write hazard
  documented in that file. A dedicated create endpoint is a reasonable follow-up, not
  required by S0.5.
- **Checkpoint `client_platform` grant refusal** — an IdP-side enhancement that would make
  "this bearer came from desktop" distinguishable server-side. Optional defence in depth.
- **Validator duplication** — S0.5 and S0.5g left the same validator copied across five
  apps. That is picked up as its own step by
  [../../../checkpoint-server-auth/04-shared-validator.md](../../../checkpoint-server-auth/04-shared-validator.md),
  which builds on this work rather than revisiting it.

## Note on naming

These notes were originally filed as `S0.5-server-side-bearer-enforcement.md` and
`S0.5g-notes-dream-graph-pdf-editor.md`, after the phase IDs this document defines. They
are now filed under the plan document that schedules them. Other plans cite them in prose
as "the S0.5 completion notes" — that content is here, under the two phase headings above.
