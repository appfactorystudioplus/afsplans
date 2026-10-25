# 03 — Data model

All tables are in the shared `…_appfactorystudio` database, prefixed `brain_app_`, **written only by
`www`** (B5, `w/AGENTS.md` §5). Migrations live in `www/database/migrations`, guarded with
`Schema::hasTable()`. They exist (empty) on every tenant clone, which is why the routes that use
them are `brain.only` ([08 §8.3](08-security-and-safety.md)).

Names below are proposals; check `brain_users_subapps` / `brain_subapp_coordinator` first
([01 W6](01-current-state-audit.md)) in case one should be extended instead of duplicated.

## 3.1 Tables

### `brain_app_registry`

| Column | Type | Notes |
|--------|------|-------|
| `app_name` | `string(40)` PK | Canonical Checkpoint name (B4) |
| `label` | `string(80)` | |
| `status` | `string(12)` | `active` \| `disabled` |
| `current_manifest_version` | `string(40)` null | |
| `last_seen_at` | `timestamp` null | |
| `notes` | `text` null | |
| timestamps | | |

### `brain_app_manifests` (immutable)

| Column | Type | Notes |
|--------|------|-------|
| `id` | `id` | |
| `app_name` | `string(40)` index | |
| `manifest_version` | `string(40)` | **unique with `app_name`** |
| `manifest` | `json` | The registered document |
| `manifest_hash` | `char(64)` | SHA-256 for the idempotency check |
| `registered_by` | `string(40)` | The app name; not a user |
| `created_at` | `timestamp` | |

### `brain_app_settings`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `id` | |
| `app_name` | `string(40)` | |
| `setting_key` | `string(120)` | **unique with `app_name` and `scope_ref`** |
| `scope` | `string(12)` | `app` in v1; `member` reserved ([04 §4.7](04-settings-and-controls.md)) |
| `scope_ref` | `string(64)` default `''` | Empty for `app` scope |
| `value` | `json` | The Brain's value |
| `revision` | `unsigned int` | Increments on every write |
| `valid` | `boolean` | False if it no longer matches the current manifest schema |
| `orphaned` | `boolean` | True if the current manifest no longer declares the key |
| `updated_by` | `string(64)` | Staff `reference_num` |
| timestamps | | |

### `brain_app_setting_history` (append-only)

`app_name`, `setting_key`, `scope_ref`, `revision`, `old_value` json null, `new_value` json,
`actor`, `reason` text null, `source` (`ui` \| `rollback` \| `api`), `created_at`. Never updated or
deleted (B9).

### `brain_app_mail_handlers`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `id` | |
| `reference_num` | `string(32)` unique | |
| `app_name` | `string(40)` | |
| `event_key` | `string(120)` | **unique with `app_name`** — one handler per event in v1 |
| `handler` | `json` | The `afsemail` handler **group** the builder produces: `{ reference_num, name, handlers: { <ref>: <handler> } }` — the same JSON `MailHandlerSender` reads. On save, each handler's `emails` is emptied and its `integration` cleared: recipients come from the calling app and the provider token is injected by the Brain's send path |
| `enabled` | `boolean` | Off = the app's fallback is used |
| `revision` | `unsigned int` | |
| `updated_by` | `string(64)` | |
| timestamps | | |

History for handlers follows the same append-only pattern (`brain_app_mail_handler_history`).

### `brain_app_mail_log`

`id`, `app_name`, `event_key`, `handler_reference_num` null, `recipient_hash` (SHA-256 of the
address, **never the address**), `status` (`sent` \| `fallback` \| `failed` \| `skipped`),
`error` text null, `request_id`, `created_at`. Retention: 90 days by default (a setting).

## 3.2 Why not reuse `laravel_mail_handlers`

That table is **per tenant** — every clone has its own, edited by the tenant's own admin. Platform
handlers for platform apps must not live where a tenant can see, copy or collide with them, and
must be written by the Brain alone. A separate `brain_app_mail_handlers` table with the *same
handler JSON shape* lets the `afsemail` builder and `MailHandlerSender` be reused unchanged
([05](05-mail-and-notifications.md)).

## 3.3 What is deliberately not stored

- **No secrets.** A setting is not a place for an API key or password. The manifest schema may
  mark a key `"secret": true`, which the Brain **refuses** in v1 (secrets stay in each app's `.env`).
- **No app data.** Only configuration about the app.
- **No recipient addresses in the mail log** (only a hash), so the log cannot become a mailing list.

## 3.4 Ownership summary

| Table | Written by | Read by |
|-------|-----------|---------|
| `brain_app_registry` | `www` (staff UI, seeder) | `www` |
| `brain_app_manifests` | `www`, on a signed app call | `www` |
| `brain_app_settings`, `_history` | `www` (staff UI) | `www`, served to the owning app via the API |
| `brain_app_mail_handlers`, `_history` | `www` (staff UI) | `www` |
| `brain_app_mail_log` | `www` | `www` |

No other app touches these tables, even those sharing the database (`members`, `auth`).
