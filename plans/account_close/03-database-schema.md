# 03 — Database schema and the purge inventory

## Completion document

Not yet carried out. When it is: `complete/03-database-schema.complete.md`, with the migration
filename(s), the final column lists, and the output of `MigrationOwnershipTest`.

## 3.1 Ownership and rules

**All new schema is *created* by a `www` migration, and most of it is *owned* by `members`**
([D16](00-overview.md)). That is exactly how `members`' existing annex works: `AGENTS.md §5` says
`www` owns every migration for the shared database and `members` ships none, and that `members`' own
tables are the `members_*` set whose migrations live in `www` named `*_create_members_*.php`. So:

| Owner (who **writes** at runtime) | Tables |
|-----------------------------------|--------|
| **`members`** — the engine | `members_account_closures`, `members_account_purge_steps`, `members_identity_tombstones`, `members_account_purge_log`, `members_account_closure_settings` |
| **`www`** — the Brain | the three legal-hold columns on `brain_memberships` ([§3.2](#32-columns-added-to-brain_memberships)); every existing `brain_*` row the purge deletes |

`members` **reads** `brain_memberships` today (`Membership`, including `account_status`) and never
writes it ([AC25](01-current-state-audit.md)); that stays true — see the status-write rule in
[02 §2.6](02-state-model-and-enforcement.md). Per `AGENTS.md §5` also: `auth` owns only the
`checkpoint`-connection tables. This plan adds **no** table to `auth`'s schema — the Checkpoint
erase endpoint deletes from tables `auth` already owns.

- One file, `create_members_account_closure_tables.php` (related tables belong together), plus one
  `add_account_closure_columns_to_brain_memberships.php` — an `ALTER` on a table `www` itself owns is
  the sanctioned way to extend it (`AGENTS.md §5`, precedent
  `2026_09_19_000001_add_egress_columns_to_members_communications_recordings.php`).
- **Every create guarded** with `if (! Schema::hasTable(...))`. **Every index named explicitly**
  (`members_aclosures_…`, ≤ 64 characters). Applied with a plain `php artisan migrate --force`; in
  the lab, `vagrant provision web --provision-with app-www`.
- `MigrationOwnershipTest` (`www/tests/Unit/Database/`) must pass untouched — it parses the
  migration files, so it is the guardrail for the rules above.
- **Never `migrate:fresh`.** It drops ~140 shared tables. Nothing in this plan needs it.

## 3.2 Columns added to `brain_memberships`

| Column | Type | Meaning |
|--------|------|---------|
| `legal_hold_at` | `timestamp` null | Set → purge is **blocked** and hard delete refuses ([08 §8.5](08-security-and-safety.md)). |
| `legal_hold_reason` | `string(500)` null | Why. |
| `legal_hold_by` | `string(100)` null | Actor username. |

That is all. **The timers deliberately do not live on the member row** — they belong to the
closure record ([§3.3](#33-members_account_closures)), which is the source of truth, so a member
who has closed and returned has a history rather than overwritten columns.

`account_status` (existing) gains the values 3/4/5 by [02 §2.2](02-state-model-and-enforcement.md) —
**no schema change**, it is a `tinyInteger`. `account_close_date` (existing `date`) becomes a
**derived** copy of the open closure's `login_block_at`, written only by `MembershipLifecycle` in
the same transaction, so the existing admin screen and `CoordinatorSettingsClient` keep working
unmodified.

`auth` reads `brain_memberships` with `SELECT *` ([AC6](01-current-state-audit.md)); the three new
columns are harmless to it.

## 3.3 `members_account_closures`

One row per closure **attempt**, of any kind. A member can accumulate several over time
(closed → restored → closed again); at most one is *open*.

| Column | Type | Meaning |
|--------|------|---------|
| `id` | bigint PK | |
| `reference_num` | `string(16)` unique | `clo` + random, shown to admins and in notices |
| `member_reference_num` | `string(32)` | The member. Indexed. **Not** a foreign key ([AC15](01-current-state-audit.md) — nothing references `brain_memberships`, and this must survive the row's deletion). |
| `user_id` | bigint null | The Checkpoint id, **captured at request time** — the purge needs it after `brain_memberships` is gone. |
| `kind` | `string(20)` | `admin_soft`, `admin_hard`, `self_service` |
| `phase` | `string(20)` | `grace`, `closed`, `purging`, `completed`, `cancelled`, `restored`, `failed` |
| `open_key` | `string(32)` null **unique** | `= member_reference_num` while `phase ∈ {grace, closed, purging}`, else `NULL`. MySQL permits many NULLs in a unique index, so this is **"one open closure per member" enforced by the database**, the same generated-value technique the builder plan uses for active slugs and `laravel_direct_payment_payer_customers.active_key` uses. |
| `requested_at` | timestamp | |
| `requested_by_type` | `string(10)` | `member` or `admin` |
| `requested_by_user_id` | bigint null | Staff id (admin) or Checkpoint id (member) |
| `requested_by_username` | `string(100)` null | |
| `reason` | text null | Required for admin actions |
| `options` | json null | `{ "l3_sites": "keep|maintenance", "l4_mail": "keep|disable", "l5_billing": "keep|cancel", "notify_member": bool, "retire_identifiers": bool, "export_requested_at": ts }` |
| `notices` | json null | `{ "<notice_key>[:<n>d]": "<sent_at>" }` — which notices have gone out, so a reminder never sends twice ([07 §7.2](07-notifications-and-scheduling.md)) |
| `login_block_at` | timestamp null | Self-service: the end of the grace period. Admin soft delete: `= requested_at`. |
| `purge_after` | timestamp null | Self-service: the end of the retention window. **`NULL` for an admin soft delete — no timer** ([D4](00-overview.md)). |
| `blocked_at` | timestamp null | When status actually became 4 |
| `purge_started_at` / `purge_completed_at` | timestamp null | |
| `cancelled_at`, `cancelled_by` | timestamp / string null | |
| `email_snapshot`, `username_snapshot` | string null | Captured at request so notices and the tombstone can be produced **after** the source rows are gone. **Nulled when the closure completes** ([§3.8](#38-members_account_purge_log)). |
| `last_error` | text null | Last failure, for the admin panel |
| `created_at`, `updated_at` | | |

Indexes (explicitly named): `members_aclosures_ref_uq` (`reference_num`), `members_aclosures_open_uq`
(`open_key`), `members_aclosures_member_idx` (`member_reference_num`, `phase`),
`members_aclosures_block_idx` (`phase`, `login_block_at`), `members_aclosures_purge_idx`
(`phase`, `purge_after`) — the last two are the sweep's two queries ([07 §7.2](07-notifications-and-scheduling.md)).

## 3.4 `members_account_purge_steps`

The ledger that makes a purge resumable ([D6](00-overview.md)). One row per step per closure,
created up front from the ordered step list so an admin can see the *whole* plan of a running
purge, not just what has run.

| Column | Type | Meaning |
|--------|------|---------|
| `id` | bigint PK | |
| `closure_id` | bigint | → `members_account_closures.id` (an internal FK is fine — both are `brain_*`) |
| `ordinal` | smallint | Execution order |
| `step_key` | `string(40)` | `block`, `billing`, `tenants`, `dns`, `mail`, `storage`, `communications`, `app:coordinator`, `app:notes`, `app:flow`, `app:dream_graph`, `app:pdf_editor`, `app:market`, `parse`, `www_tables`, `checkpoint`, `finalize` |
| `status` | `string(12)` | `pending`, `running`, `done`, `skipped`, `failed` |
| `attempts` | smallint | |
| `preview` | json null | The dry-run result: what this step *would* remove |
| `result` | json null | What it *did* remove (counts, paths) |
| `started_at`, `finished_at` | timestamp null | |
| `last_error` | text null | |

Unique `members_apsteps_closure_step_uq` (`closure_id`, `step_key`); index
`members_apsteps_status_idx` (`status`, `closure_id`).

## 3.5 `members_identity_tombstones`

Written **only** when an admin ticks "retire these identifiers" on a hard delete
([02 §2.7](02-state-model-and-enforcement.md)).

| Column | Type | Meaning |
|--------|------|---------|
| `id` | bigint PK | |
| `kind` | `string(10)` | `email` or `username` |
| `value_hmac` | `char(64)` | HMAC-SHA256 of the lower-cased, trimmed value, keyed with a server secret — **never the plain value** |
| `closure_reference` | `string(16)` | The closure that retired it |
| `reason` | `string(255)` null | |
| `created_at` | timestamp | |
| `released_at` | timestamp null | `NULL` = retired permanently; set by an admin to give it back |

Unique `members_idtomb_kind_hmac_uq` (`kind`, `value_hmac`).

## 3.6 The purge inventory

**This table is the load-bearing artefact of the plan.** Nothing cascades from
`brain_memberships` ([AC15](01-current-state-audit.md)), so a table missing from this list is a
table that leaks the member's data after a "hard delete". Every row is a step of the purge
([05](05-purge-engine.md)), in execution order.

**Action** — **D**elete the rows · **A**nonymize (drop identifying columns, keep the record) ·
**R**etain (deliberately left; reason given). Classification is [D9](00-overview.md); the
financial/other-party rows depend on OQ-E and OQ-F and are marked *(OQ)*.

Keys: `M` = `member_reference_num`; `P` = `payer_reference_num`; `U` = Checkpoint `user_id`.

**Who executes each step.** The engine is in `members` ([D2](00-overview.md)), but not every store is
`members`' to touch. Three executors, and the split is the ownership rule (`members_*` direct,
`brain_*` through the Brain, other apps' tables through those apps):

| Step | Executor | How |
|------|----------|-----|
| `block` | `members` engine → **`www`** (status) · **`auth`** (watermark, ban) · **coordinator** (API tokens) · `members` (`users_email`) | signed / system-key calls; local write |
| `billing` | **`www`** | Brain-surface call — provider cancel and the `laravel_direct_payment_*` / `laravel_processor_*` rows are `www`'s ([D18](00-overview.md)) |
| `tenants` | `members` (shell, `members_websites*`) **+ `www`** (`brain_http_server_tenants`) | `members`' runner does the remote removal; the Brain row is a Brain-surface delete |
| `dns` | `members` (shell) **+ `www`** (`brain_dns_*`) | same split |
| `mail` | `members` | shell `--delete-email-account` (implemented), `users_email` (its `EmailRetentionAdapter::purge` already deletes rows), `members_email_*` |
| `storage` | `members` | shell `--purge-member-storage`, `members_storage_*`, `members_user_files`; `laravel_documents` via the Brain surface |
| `communications` | `members` | `members_communications_*` |
| `app:*` | each satellite app | `members` calls its signed eraser route |
| `parse`, `checkpoint` | **`auth`** | `members` calls `auth`'s system-key `erase` |
| `www_tables` | **`www`** | Brain-surface delete of the `brain_*` rows below |
| `finalize` | `members` (log, tombstones, closure) **+ `www`** (the `brain_memberships` delete — last) | see [05 §5.2](05-purge-engine.md) |

### Step `block` — access removal (also the whole of a soft delete)

| Store | Change |
|-------|--------|
| `brain_memberships.account_status` | → 4 or 5 |
| `checkpoint_primary_logout_watermarks` (auth) | insert `(U, now)` |
| `ext_member_users.status`, `force_logout` (auth) | banned; +1 |
| `checkpoint_secondary_users_sessions` (auth) | mark revoked |
| `afsconnect_api_tokens` (coordinator DB) | disable |
| `users_email.status` | `disabled` **if** the admin chose L4 |

### Step `billing` — provider first, then local

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| Provider subscriptions (Stripe / PayPal / Square) | provider | `provider_subscription_id` | **cancel** | `SubscriptionActionsProvider::cancel`; **before** any local row is touched, so a failure leaves data intact |
| `laravel_processor_connected_accounts` (Stripe Connect) | www | `M` | **D** | after the account is disconnected/closed at Stripe; a **blocker** if payouts are pending (OQ-F) |
| `laravel_processor_customers` | www | `M`/`P` | **D** | provider-side customer left in place (OQ-E) |
| `laravel_processor_migration_accounts`, `laravel_processor_migrations.created_by_member_reference_num` | www | `M` | **D** | |
| `laravel_direct_payment_payers` | www | `P` | **A** | drop email/name; keep the row so invoices still resolve *(OQ-E)* |
| `laravel_direct_payment_payer_customers` | www | `P` | **A** | keep provider ids only if OQ-E demands; else **D** |
| `laravel_direct_payment_subscriptions` | www | `P` | **A** | `member_reference_num` was dropped by `2026_09_23_000001`; verify the live shape first |
| `laravel_direct_payment_invoices` | www | `P` | **A** | `user_id` (string) cleared; amounts and dates kept *(OQ-E)* |
| `laravel_direct_payment_notification_sends`, `_default_methods` | www | `P` | **D** | |

### Step `tenants` — the member's websites

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| Tenant web root, vhost, php-fpm/pm2 process, `--stop-stripe-server` | server | `systemuser`, `subdomain` | **D** | new command, [05 §5.5](05-purge-engine.md) — **not** `--remove-website` |
| Tenant database (`<subdomain>_db`) — every `laravel_*` table inside it: site users, forms, ecommerce, events, appointments, builder output | tenant DB | — | **D** (drop DB) | the member's *customers'* data goes with it (OQ-M); **not enumerated here** — it is removed wholesale |
| `brain_http_server_tenants` (holds tenant `db_host`/`db_name` credentials in `json`) | www | `M` | **D** | credentials are read **before** deletion to drive the drop |
| `members_websites`, `members_website_deployment_progress` (via `website_reference_num`) | www | `M` | **D** | |

### Step `dns`

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| Zone files and `named` config for the member's zones | server | zone | **D** | new command; `--reload-named` after |
| `brain_dns_zones` | www | `M` | **D** | |
| `brain_dns_subdomains` (`member_reference_num`, `member_username`) | www | `M` | **D** | |

### Step `mail`

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| Postfix `vmailbox` entries + `postmap`; Maildirs under `/mnt/node1/{systemuser}/mailbox` | server | address | **D** | `--delete-email-account` is a **stub today** ([AC17](01-current-state-audit.md)) and must be implemented |
| `users_email` (`userid`, `domain`, `username`, `home`, `status`, `band`) | www | `userid` | **D** | |
| `members_email_band_usage`, `members_email_retention_events` | www | `M` | **D** | |

### Step `storage`

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| `/mnt/node1/{M}/` — `storage/0..4`, `.staging/`, `.trash/` | server | `M` | **D** | new command; **and** `/mnt/node1/{systemuser}/` module data if it is a distinct tree (OQ-B) |
| `members_storage_nodes` (self-FK cascade), `members_storage_bucket_usage`, `members_storage_sessions` (+ `_session_files`, cascade), `members_storage_retention_events`, `members_user_files` | www | `M` | **D** | |
| `laravel_documents` | www | `M` | **D** | and the files they reference |

### Step `communications`

| Store | Owner | Key | Action | Notes |
|-------|-------|-----|:------:|-------|
| `members_communications_rooms` (unique on `M`) and its cascade tree: `_sessions`, `_recordings`, `_breakout_rooms`, `_participants` | www | `M` | **D** | recordings' files are removed with the storage step |
| `members_communications_participants` rows where `M` is a **participant in someone else's room** (`member_reference_num` is nullable) | www | `M` | **A** | null the column; the row belongs to another member's session |

### Step `app:*` — the satellite apps

Called by `www` through a signed internal route per app ([05 §5.4](05-purge-engine.md)). **Each app
owns its own deletion**; `www` does not reach into their tables.

| App | Tables | Key | Action |
|-----|--------|-----|:------:|
| `coordinator` | `pm_users`, `pm_teams`, `pm_team_members`, and the rest of `pm_*`; `afsconnect_settings`, `_index`, `_links`, `_activity_log`, `_api_tokens`, `_webhook_events`, `_source_status`, `_website_sync_status`, `_engine_projects`, `_engine_commits`, `_engine_seen_users` | `M`, `owner_checkpoint_user_id`, `created_by_checkpoint_user_id` | **D** |
| `notes` | `notes_notes`, `_tags`, `_tag`, `_links`, `_attachments`, `_sync_events`, `_devices`, `_folders` | `M` | **D** |
| `flow` | `flow_projects` | `M` | **D** |
| `dream_graph` | `graph_*` (projects with `M`, `owner_id`) | `M` | **D** |
| `pdf_editor` | `pefeditor_documents`, `_document_versions`, `_document_shares` | `owner_reference_num` | **D** (shares *to* others: **D** the share) |
| `market` | `seller_profiles`, `services`, `assets` (+ files) | `M` | **D** |
| `market` | `orders`, `order_items`, `service_orders`, `contracts`, `seller_ledger_entries`, `payouts`, `reviews`, `entitlements`, `asset_downloads`, `engagement_links` | `seller_reference_num`, `buyer_reference_num`, `M` | **A** *(OQ-F)* — they involve **other people**, and a hard-deleted row breaks Connect references (`market/complete/05…:38,99`) |
| every app | Laravel defaults (`users`, `sessions`, …) | — | **D** rows for that user |

### Step `parse` — non-SQL

| Store | Owner | Key | Action |
|-------|-------|-----|:------:|
| Parse `MemberResource` (one row per member/resource/scope) | auth's Parse | `M` | **D** |
| Parse `MemberRegistry` anchor | auth's Parse | `M` | **D** |
| Parse `CheckpointAlloc_Log` | auth's Parse | `M` | **D** |

There is **no Parse delete function** today ([AC19](01-current-state-audit.md)); a `memberPurge`
cloud function is new work in `auth` ([05 §5.4](05-purge-engine.md)).

### Step `www_tables` — the Brain's own

| Store | Key | Action | Notes |
|-------|-----|:------:|-------|
| `brain_membership_events` | `M` | **D** | after copying the closure's own events into the purge log |
| `brain_membership_billing_state` | `M` | **D** | |
| `brain_membership_checkpoint_errors` | `membership_reference_num` (+ email/username text) | **D** | |
| `brain_alloc_overrides` | `M` | **D** | it soft-deletes for history; a purge is not history |
| `laravel_brain_alloc_audit` | `M` | **A** | an audit table that appears deliberately retained (`members_brain` plan) — replace `M` with a purge marker, keep the rows |
| `members_market_notifications` | `M` | **D** | |
| `users_member` (legacy) | `reference_num`, `user_id` | **D** | if the row exists (OQ-N) |
| `brain_users_subapps`, `brain_subapp_coordinator` | `super_user_id` | — | **not member columns** — a staff concept; **do not touch** |

### Step `checkpoint` — the identity (`auth`, `checkpoint` connection)

`auth` owns these ([AC14](01-current-state-audit.md)); `www` asks `auth` to delete them.
**MyISAM — no rollback**, and Delight's `deleteUserById` removes only the parent row, so each child
is deleted explicitly, **children first, `ext_member_users` last**.

| Store | Key | Action |
|-------|-----|:------:|
| `ext_member_users_2fa`, `_audit_log`, `_confirmations`, `_otps`, `_remembered`, `_resets` | `U` | **D** |
| `ext_member_users_throttling` | — (keyed by identifier/IP, not `U`) | **R** — expires by itself; not member-keyed |
| `checkpoint_user_features`, `checkpoint_2fa`, `checkpoint_2fa_pending`, `checkpoint_allocations`, `checkpoint_allocation_history` | `U` | **D** |
| `checkpoint_secondary_users` (+ `_secondary_user_apps`, `_secondary_users_sessions`, `_secondary_identifiers`) | `super_user_id = U` | **D** |
| `checkpoint_secondary_2fa_policy` | `M` | **D** |
| `checkpoint_primary_logout_watermarks` | `U` | **R for 35 days, then D** — one tiny row that keeps every pre-purge token dead until it expires (refresh token and SSO cookie live 30 days, [AC7](01-current-state-audit.md)). Pruned by the sweep ([07 §7.2](07-notifications-and-scheduling.md)) so it can never touch a stranger, even if an id were ever reused ([08 §8.7](08-security-and-safety.md)). |
| `ext_member_users` | `U` | **D** — last |
| `admin_users`, `admin_sessions`, `ext_admin_users*`, `ext_domain_users*` | — | **not touched** — staff and domain users, a different population |

### Step `finalize`

| Store | Action |
|-------|--------|
| `brain_memberships` row | **D** — **the last delete**, so an interrupted purge is always still findable by its member row |
| `members_identity_tombstones` | **insert**, if `retire_identifiers` |
| `members_account_purge_log` | **insert** ([§3.8](#38-members_account_purge_log)) |
| `members_account_closures` | `phase = completed`; **null** `email_snapshot`, `username_snapshot` |

### Out of SQL, not enumerated

Backups and log files ([08 §8.9](08-security-and-safety.md)); the provider's own copy of a
customer (OQ-E); mail already delivered to third parties.

## 3.7 Event constants added to `BrainMembershipEvent`

Additive constants beside `EVENT_ACCOUNT_STATUS_CHANGED` (`app/Models/BrainMembershipEvent.php`):

`EVENT_CLOSURE_REQUESTED`, `EVENT_CLOSURE_CANCELLED`, `EVENT_LOGIN_BLOCKED`,
`EVENT_SOFT_DELETED`, `EVENT_RESTORED`, `EVENT_PURGE_STARTED`, `EVENT_PURGE_COMPLETED`,
`EVENT_PURGE_FAILED`, `EVENT_LEGAL_HOLD_SET`, `EVENT_LEGAL_HOLD_CLEARED`.

The table is **append-only** and is itself erased at step `www_tables`, so the *survivor* record of
a purge is the purge log below, not these rows.

## 3.8 `members_account_purge_log`

The single thing that outlives a hard delete: **enough to answer "was this member purged, when, by
whom, and did it finish" without holding anything personal.**

| Column | Type | Meaning |
|--------|------|---------|
| `id` | bigint PK | |
| `member_reference_num` | `string(32)` | The random `m1x…` reference — not personal on its own |
| `closure_reference` | `string(16)` | |
| `kind` | `string(20)` | |
| `email_hmac`, `username_hmac` | `char(64)` null | Same keyed HMAC as tombstones — lets an operator confirm "was *this* address purged" |
| `actor_type`, `actor_user_id`, `actor_username`, `reason` | | Who and why |
| `steps` | json | Per step: status, attempts, row/path counts |
| `requested_at`, `purge_started_at`, `purge_completed_at` | timestamp | |

Index `members_apurgelog_member_idx` (`member_reference_num`). **No email, no username, no name.**

## 3.9 `members_account_closure_settings`

A single-row settings table **owned by `members`**, in the mould of `brain_billing_settings`
(which the Brain owns for billing).

| Column | Default | Meaning |
|--------|---------|---------|
| `self_service_enabled` | `false` | The master switch — self-service stays off until P3 |
| `grace_days` | 14 | Closing → Closed ([D10](00-overview.md)) |
| `retention_days` | 30 | Closed → Purging |
| `grace_reminder_days` | `[7,1]` | Days before `login_block_at` a notice is sent |
| `retention_reminder_days` | `[7,1]` | Days before `purge_after` |
| `default_soft_delete_options` | `{ "l3_sites":"keep","l4_mail":"disable","l5_billing":"cancel" }` | Pre-ticked in the admin modal |
| `mail_handler_<notice_key>` | null | One handler reference per notice of [07 §7.1](07-notifications-and-scheduling.md); unset → the built-in fallback ([07 §7.3](07-notifications-and-scheduling.md), [D12](00-overview.md)) |
| `operator_alert_email` | null | Where failed notices and stuck purges alert ([07 §7.6](07-notifications-and-scheduling.md)) |
| `protected_member_references` | `[]` | Members no closure endpoint may touch, beyond `level ≥ 99` ([08 §8.3](08-security-and-safety.md)) |
