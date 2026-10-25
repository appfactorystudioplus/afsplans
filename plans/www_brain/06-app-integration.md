# 06 — App integration

What each app must add to be Brain-controlled. Kept as small as possible: an app should need a
client, a manifest and a few call sites — no admin UI, no tables.

## 6.1 The five pieces per app

| # | Piece | Notes |
|---|-------|-------|
| 1 | **Client** | Signs requests, calls the Brain, caches results |
| 2 | **Manifest** | One file declaring settings, mail events and variables |
| 3 | **Register command** | Sends the manifest on deploy |
| 4 | **Settings accessor** | `Brain::setting('share.max_collaborators')` — returns the effective value |
| 5 | **Mail seam** | `Brain::mail('note_shared', $to, $variables)` |

Plus two `.env` values: the Brain base URL and the app's `WWW_BRAIN_SURFACE_KEY` (the name `members` already uses).

## 6.2 The client and how it is distributed

The apps are Laravel (PHP), so the client is a **Composer package**, `afs/brain-client`, in `packages/brain-client` —
the same shape as the existing `afs/checkpoint-auth`. It is small: a signer, a settings reader with a cache and a circuit
breaker, a mail sender, a schema validator, and two Artisan commands (`brain:register-manifest`, `brain:send-test`).

**How the apps install it today.** Each app's `composer.json` has a `path` repository pointing at
`../../packages/brain-client` (with `symlink: false`, so it is copied) and requires `afs/brain-client: *`; the package
carries an explicit `version`. That works on a developer machine and in the lab, where `packages/` sits beside the apps.
**It does not work on a real server**, where each app is deployed on its own. Before any real deployment: publish the
package as its own git repository (as `checkpoint-auth` is) and replace the `path` entry in each app with a `vcs` one. The
package keeps its own `vendor` directory **outside** the package (`../brain-client-vendor`), because a path repository
copies everything under the package directory and a nested `vendor` makes installs take many minutes.

The TypeScript `afs-client` consolidation is **not** involved: no browser code calls the Brain directly (OQ-7).

### Client behaviour (normative)

- **Signs** every call ([02 §2.5](02-app-registry-and-manifest.md)) with the app's key.
- **Timeouts:** connect 2 s, total 5 s. A settings read must never block a request for longer than
  that; on timeout use last-known-good.
- **Caching:** the three-layer model in [04 §4.5](04-settings-and-controls.md), stored in the app's
  own cache store. Requires a working cache — verify per app (P0).
- **ETag:** sends `If-None-Match`; treats `304` as "cache still good".
- **Never throws to the caller** for a Brain failure; returns the best available value and logs at
  warning level, rate-limited (a Brain outage must not flood logs).
- **Circuit breaker:** after N consecutive failures stop trying for a short window, so a dead Brain
  does not add its timeout to every request.
- **Clock:** a request outside the ±300 s window is refused; the client logs skew clearly.

## 6.3 The manifest and the register command

The manifest is a checked-in file, `config/brain_manifest.php`, returning the array in
[02 §2.2](02-app-registry-and-manifest.md)) — **defaults are read from the same file** so the
"compiled defaults" layer and the registered manifest cannot disagree.

`php artisan brain:register-manifest` posts it; idempotent. Identical content is a no-op; a changed manifest needs a new `manifest_version`. Run from the deploy
script. A failure to register **must not fail the deploy** (the app still runs on defaults), but it
must be loud — the Brain UI shows `last_seen_at` and `manifest_version` per app, so a stale
registration is visible.

## 6.4 The mail seam and the fallback transport

`Brain::mail($event, $to, $variables)`:

1. Calls the Brain's send endpoint ([05 §5.4](05-mail-and-notifications.md)).
2. On `sent` — done.
3. On `fallback`, `5xx` or timeout — **queues** the manifest fallback for the app's own transport
   *or*, if the app has none yet, logs and drops **non-essential** events (the manifest marks an
   event `essential: true|false`).

An app that wants an `essential` event (password reset, receipt) needs a real fallback transport
first. None has one today, so **essential events are gated on that work**, not on this plan.

## 6.5 Per-app notes

| App | Stack | Notes |
|-----|-------|-------|
| `notes`, `flow`, `dream_graph`, `pdf_editor`, `coordinator` | Laravel, `afs_apps_db` | Same client. `coordinator` already holds afs-connect peer keys for the others — those are **not** reused ([08](08-security-and-safety.md)) |
| `market` | Laravel; own Brain signature classes | Already has a Brain-facing signer (`BrainInternalSignature`); align it with the shared client rather than adding a second one. Data location **unverified** |
| `members` | Laravel, shares the Brain's DB | Registers like any other app (OQ-2) for one consistent path even though it could read the DB |
| `auth` | Laravel + Parse | Out of scope v1 (OQ-2) |
| `GAME_EDITOR`, `APPFACTORY_MOBILE` | Not Laravel (C++ / Flutter) | Have canonical names but **no server**; if they ever need settings, their backend fetches on their behalf. Not in v1 |

## 6.6 What an app must NOT do

- Write to `brain_*` tables, even where it shares the database.
- Cache a setting **forever without a TTL** for a non-`hot` key (last-known-good is a fallback, not
  the primary path).
- Send mail any way other than the seam, once it has one.
- Trust a value from the Brain without validating it against **its own** schema (B8).
- Read a setting in a hot loop; read once per request/boot and reuse.

## 6.7 Effort per app (rough)

Client + config + one `.env` pair + manifest + register command + call sites for the pilot's two
settings: **small** — the point of B3 is that later controls cost only a manifest line and a
`Brain::setting()` call at the point of use.

## 6.8 As built

| App | State |
|-----|-------|
| `notes` (pilot) | Package installed, `config/brain_manifest.php` declares `maintenance.enabled`, `maintenance.message` and one mail event (`brain_test`), and `BrainMaintenance` middleware applies the maintenance setting to every web and API route except `up` and `internal/*`. Env: `BRAIN_APP_NAME`, `WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY` |
| `flow`, `dream_graph`, `pdf_editor`, `coordinator`, `market`, `members` | Package, an **empty** manifest (registered, nothing controlled), the same env variables. A control is added only against a named need (P5) |
| `auth` | Not an app of the Brain in v1 |

`brain:register-manifest` is **not** yet in any deploy script (the lab's `deploy-app.sh` runs `migrate` only); run it by hand or add it to the deploy step.
