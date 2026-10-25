# 08 — Live Sync Protocol (Web ⇄ JoystickEngine)

> **Requirement:** "in the future the tile map editor would be able to connect
> to the game engine and export the tile map to the game engine and vice versa."
> Doc 06 §§1–4 delivered the *file-based* half. This doc specifies the **live**
> half: an authenticated, bidirectional, conflict-safe channel so a paint on
> the website appears in the running engine editor within seconds, and a paint
> in the engine flows back — without either side ever exporting a file by hand.

This is the web-side contract. The engine builds its matching client in
`JoystickEngine_v2/plan/steps/47_tilemap_physics_scripting.md` §47.8 (over its
step-40 editor bridge and step-49.5 asset refresh). The two docs describe **one
protocol from two ends**; where they overlap they must agree, and any change is
a versioned event on both (doc 01 §3).

## 0. Design stance

- **The engine is the client; the web app is the server.** No inbound
  connection to the user's machine is ever required — the engine dials out.
  This is the only posture that works behind NAT/firewalls and matches the
  engine's own bridge (step 40) and toolchain-resolution discipline (step 44):
  the client discovers config in a defined order and never guesses.
- **Nothing new is invented that the file layer didn't already freeze.** The
  wire payload is the exact §06 zip and the exact §03 chunk RLE strings. Live
  sync is *transport + versioning + auth* around serializers that already exist
  and are already CI-round-tripped (doc 01 §3). This keeps the "export =
  transcoding" property true for the live path too.
- **Conflicts are resolved at whole-chunk granularity, never finer.** A chunk
  is always internally consistent (doc 03 §6); two writers touching the same
  chunk is a version conflict, arbitrated exactly as two browser tabs already
  are (doc 03 §4). Sub-chunk 3-way merge is explicitly out of scope (§9).
- **Identity is the engine's `.jmeta` `Origin` block** (engine 48.6 / 49.6),
  keyed by our `tilemap_uuid`/`tileset_uuid`/`asset_uuid`. We assign uuids; the
  engine binds to them. Neither side ever mints identity for the other's object.

## 1. Roles, transport, topology

```
   ┌─────────────────────┐        HTTPS (REST + SSE)        ┌────────────────────┐
   │  JoystickEngine      │  ───────  pull  ───────────────▶ │  Dream Graph (web) │
   │  editor (step 40     │  ◀──────  push  ───────────────  │  Laravel + Reverb  │
   │  bridge + 47.8       │  ◀──────  SSE notify  ─────────  │  (graph phase 8)   │
   │  DreamGraphSync)     │                                   │                    │
   └─────────────────────┘                                   └────────────────────┘
        client (dials out)                                         server
```

- **Transport:** ordinary HTTPS against the existing REST surface (doc 03 §3),
  plus one server→client **SSE** stream (or long-poll fallback) for "map N
  changed" notifications so the engine needn't poll blindly. Reverb (graph
  phase 8) backs the realtime channel; the SSE endpoint is a thin bridge over
  the same events for a client that isn't a browser holding a websocket.
- **No new binary protocol.** Pull is a conditional GET of the §06 export;
  push is the §03/§06 import. SSE carries only tiny `{tilemap_uuid, version}`
  hints — never payload — so the engine decides when to pull.
- **Scope of a session:** one engine editor instance ↔ one web project, via one
  sync token (§3). Multiple maps in that project multiplex over the same token.

## 2. Pull (web → engine): conditional export

The engine asks for a map's current state; the server answers cheaply when
nothing changed.

```
GET /api/tilemaps/{uuid}/export?format=joystick&since_version=41
Authorization: Bearer <sync-token>
If-None-Match: "jtm-42"                    # optional; ETag from the last pull
```

- **`since_version`** — the engine's last-seen `tilemaps.version`. If
  `tilemaps.version <= since_version` → **`304 Not Modified`**, empty body. This
  is the common case during idle editing and keeps the poll/notify loop nearly
  free.
- Otherwise → **`200`** with the §06 `joystick-tilemap/1` zip and headers:
  ```
  ETag: "jtm-42"
  X-Tilemap-Version: 42
  Content-Type: application/zip
  ```
- The engine writes the zip into `Assets/` through its importer (engine 47.6),
  its file-watcher hot-reloads the open editor (engine 47.8), and it records
  `42` as the new `since_version` for this map.
- **Determinism matters here:** because the export is byte-deterministic (doc
  06 §5, chunks sorted `(cy, cx)`), the ETag can be a cheap function of
  `(uuid, version)` and the engine can also short-circuit a no-op write by
  content hash if a version bumped without the exported bytes changing (e.g. a
  viewport-only save).

### 2.1 When the engine pulls

- On SSE `changed` for a map it has open (§6) — the responsive path.
- On a timer as a fallback when SSE is unavailable (long-poll or a slow poll,
  e.g. 15 s) — never faster than the debounce that produced the change.
- On engine editor focus-gain for any open synced map (mirrors the web's own
  focus-refresh, doc 03 §5; engine 49.5 `Refresh`) — catches anything missed
  while the engine was backgrounded.

## 3. Authentication — per-project sync tokens

The web app already has project membership + `ProjectPolicy` (doc 03). Live
sync adds a **narrow, revocable machine credential** so a headless engine
process authenticates without a browser session.

- **Issuance:** project settings → *Engine Sync* → *Create sync token*. Backed
  by Sanctum personal-access tokens **scoped to abilities** `tilemap:read`
  and/or `tilemap:write`, and **bound to one project** (a custom ability check,
  not just the ability string — a token for project A can't touch project B).
  Shown once, copied into the engine's project settings (a field beside step
  42's "Code editor command").
- **Scope enforcement:** the export endpoint requires `tilemap:read`; import
  requires `tilemap:write`. A read-only token can pull-sync a reference map
  into a game without being able to push edits back.
- **Revocation:** listed in project settings with last-used timestamp + a
  revoke button; revoking is immediate (token row deleted). A revoked token
  gets `401`; the engine surfaces "sync disconnected — re-issue a token."
- **Rate + size limits:** the token's requests are rate-limited per project
  (standard throttle) and the import caps (≤256 chunks/flush, ≤8 KB/chunk, doc
  03 §6) apply identically — a machine client is not more trusted than a tab.
- **Transport security:** HTTPS only; token never in a URL (Authorization
  header only — doc 01's "never personal data in query strings" discipline).

## 4. Identity mapping — the address book both directions

| Web (server) | Engine (client) | Bound by |
|---|---|---|
| `tilemaps.uuid` | the map group's `.jmeta` `Origin.ObjectUuid` | recorded on first import (engine 47.6), read back on push |
| `tilesets.uuid` | the `.jtileset` asset's `Origin.ObjectUuid` (engine 49.6 index) | dedupe/reuse key — re-import updates in place |
| `assets.uuid` (atlas texture) | the texture `.jmeta` `Origin.ObjectUuid` | checksum-fallback relink when Origin absent |
| `tilemaps.version` | `since_version` (pull) / `base_version` (push) | the optimistic-concurrency counter (§2, §5) |

The engine **never invents** a `tilemap_uuid`; a map that originated in the
engine and is pushed for the first time is a *create* (§5.2), and the server's
assigned uuid comes back and is written into the engine's `Origin` block so all
subsequent syncs are updates.

## 5. Push (engine → web): versioned import

The engine's tilemap save hook (engine 46.5's dirty-chunk walk) posts changes
chunk-granularly — the same shape as the web's own autosave flush (doc 03 §4),
so this reuses that machinery wholesale.

### 5.1 Update an existing (web-originated) map

```
POST /api/tilemaps/{uuid}/import
Authorization: Bearer <sync-token>       # needs tilemap:write
Content-Type: application/json
{
  "base_version": 42,
  "chunks": [
    { "layer_uuid": "…", "cx": 3, "cy": -1, "data": "AQAAAA…" },   // upsert
    { "layer_uuid": "…", "cx": 4, "cy": -1, "data": null }          // became empty → delete
  ],
  "meta": { "cell_size": 1.0 },          // optional map/layer property changes
  "tile_meta": { "…tileset_uuid…": { "12": { "solid": true } } }   // optional
}
```

- **Same validation as doc 03 §6** — every chunk decoded, counts sum to 1024,
  `used_count` recomputed, never trusted; caps enforced. A malformed batch is
  rejected whole, zero partial writes.
- **`200 { "version": 43 }`** on success (`version++`, one transaction).
- **`409 Conflict`** when `base_version` ≠ the row's current version:
  ```json
  { "error": "version_conflict", "current_version": 44,
    "conflicting_chunks": [ { "layer_uuid": "…", "cx": 3, "cy": -1 } ] }
  ```
  The engine resolves it with the **same three choices** the web offers between
  two tabs (engine 46.6): *Keep mine* (re-pull the current version, re-base the
  local chunks on top, re-push), *Take web* (pull and discard the local change),
  *Duplicate* (create a new web map from the local state, §5.2). Whole-chunk
  granularity means "re-base" is a clean per-chunk last-writer-wins choice, not
  a text merge.

### 5.2 Create a map that originated in the engine

The engine can author a tilemap from scratch and publish it. This is a full
`joystick-tilemap` bundle upload (doc 06 §3), not a chunk delta:

```
POST /api/projects/{project}/tilemaps/import    (multipart: the §06 zip)
```

The server runs the doc 06 §3 import (tileset dedupe, verbatim-after-validation
chunks, manifest assembly), assigns fresh uuids, and returns the created
`tilemap_uuid` + `tileset_uuid`s + version. The engine writes those into the
map's `.jmeta` `Origin` blocks — from then on it's an update (§5.1).

### 5.3 Offline queueing

When the web app is unreachable mid-edit, the engine must not lose work or
block painting:

- Pushes queue locally (an append-only journal of `{tilemap_uuid, base_version,
  chunk-set}` per save), coalesced per chunk (a chunk edited five times offline
  pushes once, latest state).
- On reconnect the queue flushes oldest-map-first; each flush is an ordinary
  §5.1 push and hits the ordinary 409 path if the web advanced meanwhile.
- The queue is bounded and survives an engine restart (it's just files under
  the project). This is engine-side (engine 47.8 names it as deferred detail);
  the web contract is only that a delayed push is indistinguishable from a
  prompt one — versioning already handles arbitrary ordering.

## 6. Realtime notifications (server → client)

So the engine pulls *when there's something to pull* rather than on a blind
timer:

```
GET /api/projects/{project}/tilemap-sync/stream        (text/event-stream)
Authorization: Bearer <sync-token>                     # tilemap:read

event: changed
data: { "tilemap_uuid": "…", "version": 44 }

event: heartbeat
data: { "t": "2026-07-24T…" }
```

- Backed by Reverb (graph phase 8): the same `TilemapChunksSaved` /
  `TilemapUpdated` events that notify open browser tabs are bridged to this SSE
  stream for the engine client. An engine push (§5) and a browser autosave both
  emit the event, so the engine hears about browser edits and *its own* edits'
  canonical version alike.
- **Loop suppression:** the engine ignores a `changed` whose `version` it just
  produced by its own push (it knows the version it received in the §5 `200`).
  This prevents push→notify→pull→push feedback.
- SSE is an optimization; §2.1's timer + focus fallbacks make the system
  correct without it (a missed event just delays a pull to the next tick).

## 7. Engine-side responsibilities (summary — full spec in engine 47.8)

Named here only so the web contract's assumptions are explicit; the engine plan
owns the detail:

- Store the sync token per-project; resolve it in the same "defined order,
  never guess" way step 44's toolchain resolution does.
- Pull → import (47.6) → hot-reload; suppress no-op writes by content hash.
- Push on save (46.5 dirty walk) with `base_version` from the `Origin` block.
- Handle 304/409/401 per this doc; surface conflicts as engine 46.6's
  merge-or-fork prompt.
- Keep the `.jmeta` `Origin` blocks authoritative for identity (48.6/49.6).

## 8. Verify (CI + manual, shared with engine 47.9)

| Check | Expect |
|---|---|
| Pull with `since_version` ≥ current | `304`, empty body, no file write engine-side |
| Pull after a browser paint | `200` + zip + `X-Tilemap-Version`; engine viewport hot-reloads within seconds |
| Push a clean chunk delta (`base_version` current) | `200 {version+1}`; open browser tab sees it via Reverb/SSE |
| Push with a stale `base_version` (web advanced) | `409` + `conflicting_chunks`; engine shows merge-or-fork; no partial write |
| Engine-authored map, first push (§5.2) | web creates it, returns uuid; engine writes `Origin`; second push is an update |
| Round trip: web paint → pull → engine paint → push → web | both edits present; version monotonic; **no duplicate map/tileset** (Origin index, engine 49.6) |
| Revoke the token mid-session | next request `401`; engine reports "sync disconnected"; no crash, no data loss (queued locally) |
| Read-only token attempts a push | `403` (ability scope); pull still works |
| Loop suppression | engine push does not trigger the engine to re-pull its own change |
| Offline burst then reconnect (§5.3) | coalesced pushes flush in order; conflicts hit the normal 409 path |
| Malicious/oversized push (fake chunks, >256, >8 KB) | rejected by doc 03 §6 validation; zero writes |

## 9. Explicitly out of scope (v1 of the live protocol)

- **Sub-chunk merge.** Conflicts are whole-chunk; finer merging would need edit
  history on the wire, which neither side keeps (both store state, not history —
  doc 03 §4, engine 46.4).
- **Multiple concurrent engine clients** on one project. One engine editor per
  project-token session in v1; two engines pushing is just two writers hitting
  the 409 path, correct but unoptimized.
- **Live tileset *image* streaming.** A changed atlas PNG re-syncs as a file
  (pull/import), not as a live pixel channel.
- **Play-mode / runtime `SetTile` sync.** The bridge syncs *authoring* state;
  a running game's mutations (engine 47.2) are not published.
- **Auth UX beyond paste-a-token** (OAuth device flow, auto-provisioning) —
  deferred to when the engine's bridge UX is designed for real (TM5b, doc 07).
