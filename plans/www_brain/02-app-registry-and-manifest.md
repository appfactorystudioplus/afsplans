# 02 — App registry and manifest

How an app becomes known to the Brain, and how it says what is configurable.

## 2.1 The registry

One row per app in `brain_app_registry` ([03](03-data-model.md)). Identity is the canonical
Checkpoint name (B4). The registry answers: *which apps exist, are they enabled, what manifest
version is current, when did they last check in.*

| Field | Meaning |
|-------|---------|
| `app_name` | `MARKET`, `NOTES_EDITOR`, … — the primary key |
| `label` | Display name (from `CHECKPOINT_APP_LABELS`, editable) |
| `status` | `active` \| `disabled` — a disabled app is refused by every app→Brain route |
| `current_manifest_version` | Pointer into the manifest history |
| `last_seen_at` | Updated by any authenticated app call (so the UI can show a dead app) |

Registration is **staff-created**, not self-service: an app cannot invent itself. Staff add the app
row (or a seeder does for the known six), and the app then authenticates with its key and registers
manifests against its own row only.

## 2.2 The manifest

A manifest is JSON the app produces from its own code. It is **versioned** and immutable once
registered.

```json
{
  "app": "NOTES_EDITOR",
  "manifest_version": "2026.09.24-1",
  "settings": [
    {
      "key": "maintenance.enabled",
      "label": "Maintenance mode",
      "group": "Operations",
      "schema": { "type": "boolean" },
      "default": false,
      "hot": true,
      "description": "Show the maintenance page to everyone except staff."
    },
    {
      "key": "share.max_collaborators",
      "label": "Max collaborators per note",
      "group": "Sharing",
      "schema": { "type": "integer", "minimum": 1, "maximum": 100 },
      "default": 10
    }
  ],
  "mail_events": [
    {
      "key": "note_shared",
      "label": "A note was shared with you",
      "variables": [
        { "name": "recipient_name", "type": "string", "sample": "Sam" },
        { "name": "note_title",     "type": "string", "sample": "Q4 plan" },
        { "name": "share_url",      "type": "string", "sample": "https://…" }
      ],
      "fallback": { "subject": "A note was shared with you", "body_text": "…" }
    }
  ]
}
```

Rules:

- **`schema` is JSON Schema** (a restricted subset: `type`, `enum`, `minimum`/`maximum`,
  `minLength`/`maxLength`, `items`, `properties`, `required`). The Brain validates writes against it
  (B8); the UI generates a form from it ([07 §7.4](07-brain-admin-ui.md)).
- **`default` is mandatory** and is also compiled into the app (B2), so a setting always has a value.
- **`hot: true`** marks a setting the app re-reads on its short TTL; unmarked settings are read at
  boot or on cache miss ([04 §4.5](04-settings-and-controls.md)).
- **Mail variables are flat `{name, type, sample, html?}` with string/number types only**, the same
  restriction and shape already used by `www/app/Mail/variables.json` and the `afsemail` variables
  dialog. Complex values (lists of products, etc.) are pre-rendered by the app to an HTML string and declared `"html": true`; every other variable is HTML-escaped when it is put in a message ([05 §5.2](05-mail-and-notifications.md)).
- **`fallback`** is the built-in message the app sends if the Brain has no handler or is
  unreachable ([05 §5.5](05-mail-and-notifications.md)).
- **Keys are dotted, lowercase, stable.** Renaming a key is a new setting; removal is an explicit
  `deprecated: true` for one manifest version before deletion, so a Brain value is not silently
  orphaned.

## 2.3 Registering a manifest

`POST /internal/brain/apps/{app}/manifest` (signed, [2.5](#25-the-channel)) with the JSON above.

| Case | Result |
|------|--------|
| New version, valid | Stored, becomes current, `200` with a diff summary (added/removed/changed keys) |
| Same version, identical body | `200`, no-op (idempotent — safe to run on every deploy) |
| Same version, **different** body | `409` — versions are immutable |
| Invalid manifest | `422` with the failing path |
| Removes a key that has a stored value | Accepted; the value is **kept, flagged orphaned**, and shown in the UI (never silently dropped) |
| Changes a schema so a stored value no longer validates | Accepted; the stored value is flagged **invalid** and the app receives the **default** for that key until staff fix it (B8) |

The app runs a `brain:register-manifest` artisan command (name to confirm, [06 §6.3](06-app-integration.md))
from its deploy script. It is idempotent, so running it on every deploy is correct.

## 2.4 What the Brain does with it

- Keeps the full manifest history.
- Builds the **Apps → {app}** pages from the current manifest: settings grouped by `group`, mail
  events with their variable lists feeding the `afsemail` variables dialog.
- Computes `effective = brain value if valid, else manifest default` for every read.
- Never trusts the manifest for authorization — it describes *what*, never *who may*.

## 2.5 The channel

App → Brain, over HTTPS, signed per app (B7):

- **Headers:** `X-Brain-App` (canonical name), `X-Brain-Timestamp`, `X-Brain-Signature`
  (HMAC-SHA256 over method + path + timestamp + SHA-256 of the body), and a nonce for POSTs.
- **Skew:** ±300 s, matching the existing afs-connect setting.
- **Key:** `WWW_BRAIN_SURFACE_KEY` in the app's `.env` (as `members` already does); the Brain holds
  `BRAIN_SURFACE_KEY_<APP>` per app in `www/config/brain.php` (`surface.apps.<APP>`) with a `_NEXT`
  counterpart for rotation.
- **Never** `WWW_INTERNAL_API_KEY` (opposite direction) and **never** afs-connect's keys.
- **Shared plumbing — already built.** The signer, the verifier and the `internal/brain` route group
  exist ([`billing/08`](../billing/08-brain-enforcement.md) §8.2): `BrainSurfaceSignature` on both
  sides, the `brain.only` and `brain.surface` middleware, and `members`' `BrainSurfaceClient`. An app is
  added by giving it an entry in `config('brain.surface.apps')` and a key pair; the headers
  (`X-Brain-App`, `-Timestamp`, `-Nonce`, `-Signature`) and the signature construction are already
  fixed.
- **Route group:** `Route::prefix('internal/brain/apps')->middleware(['brain.only','brain.surface'])`,
  separate from every existing `api/v1/brain` group ([01 W1](01-current-state-audit.md)).

Routes an app may call:

| Route | Purpose |
|-------|---------|
| `POST /api/internal/brain/apps/{app}/manifest` | Register a manifest (2.3) |
| `GET  /api/internal/brain/apps/{app}/settings` | Read effective settings, with `ETag` ([04 §4.4](04-settings-and-controls.md)) |
| `POST /api/internal/brain/apps/{app}/mail/send` | Ask the Brain to send a configured message ([05 §5.4](05-mail-and-notifications.md)) |
| `POST /api/internal/brain/apps/{app}/heartbeat` | `last_seen_at` and the manifest version the Brain holds |

`{app}` in the path is **cross-checked against `X-Brain-App`** (`403 APP_MISMATCH`); an app can only ever address itself. An app with no registry row gets `403 APP_UNKNOWN`, a disabled one `403 APP_DISABLED` — stable codes the client reads as "use your defaults", not "retry". Every call from a registered, active app updates its `last_seen_at`. `php artisan brain:apps-seed` adds the known apps to the registry (idempotent; Brain only).
