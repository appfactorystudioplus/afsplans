# 01 — Current-state audit

Read from the code on 2026-09-24. "Verified" means the file was opened; **unverified** means
inferred or not read.

## 1.1 Findings

| # | Finding | Evidence | Consequence |
|---|---------|----------|-------------|
| **W1** | The Brain admin has three `api/v1/brain` route groups: (1) `portal.admin.auth` only — members, config, logs, DNS, HTTP servers; (2) `afs_connect.service` (signed) — `entitlement/{member}`; (3) `portal.admin.auth` + `portal.level99` — member allocation and overrides | `www/routes/portal.php:457–560` | The new endpoints need their **own** groups. Group 1 has no level check (its `portal.level99` was disabled 2026-08-29 because "level 99 was meant to identify the APPLICATION, not a user"); group 3 is staff-only |
| **W2** | A service-to-Brain signing mechanism already exists: `AfsConnectServiceAuthMiddleware` verifies `X-AfsConnect-App`, `-Timestamp`, `-Signature` against a per-sender key from `config('afs_connect.peer_keys')`, 300 s skew | `www/app/Http/Middleware/AfsConnectServiceAuthMiddleware.php` | Right *shape* for app→Brain. Its keys are afs-connect's; reusing them widens their blast radius — mint new ones ([08 §8.2](08-security-and-safety.md)) |
| **W3** | `www/.env` holds peer keys named for COORDINATOR, WWW, MEMBERS; `coordinator/.env` holds keys for NOTES, DREAM_GRAPH, FLOW, PDF_EDITOR, MEMBERS, WWW (names only were read; **values were not inspected**) | grep of `.env` key names | Per-app key pattern is established; a `BRAIN_APP_KEY_<APP>` family fits it |
| **W4** | `market` has its own signature classes: `VerifyBrainSignature`, `BrainInternalSignature` (payouts to the Brain), `MARKETPLACE_INTERNAL_API_KEY` | `market/app/Http/Middleware`, `market/app/Services/Payouts` | `market` already talks to the Brain; it is the only app with a Brain-facing client today |
| **W5** | Canonical app identifiers exist: `MEMBERS`, `COORDINATOR`, `NOTES_EDITOR`, `PDF_EDITOR`, `FLOW_EDITOR`, `GRAPH_EDITOR`, `GAME_EDITOR`, `MARKET`, `APPFACTORY_STUDIO`, `APPFACTORY_MOBILE`, "kept in sync with `auth/app/Services/Checkpoint/CheckpointAppName.php`" | `packages/checkpoint-client/app-names.ts` | Decision B4 reuses them. `GAME_EDITOR` and `APPFACTORY_MOBILE` are not Laravel apps — see [06 §6.5](06-app-integration.md) |
| **W6** | `brain_*` tables today: `brain_config`, DNS (4), HTTP (4), `brain_users_subapps`, `brain_subapp_coordinator`, `brain_memberships`, `brain_membership_checkpoint_errors`, `brain_membership_billing_state`, `brain_membership_events`, `brain_billing_settings`, `laravel_brain_alloc_audit`. **No app-level table.** | `create_brain_tables` migration | `brain_users_subapps` / `brain_subapp_coordinator` sound related; their purpose is **unverified** — read them before naming new tables ([09](09-rollout-and-testing.md) P0) |
| **W7** | The Brain admin nav item is shown only when `member.level == 99`; that hides a **sidebar link**, not an API | `www/resources/js/portal/admin/main.ts` | Authorization must be server-side on every new route ([08](08-security-and-safety.md)) |
| **W8** | Brain admin UI code: `controllers/brain/BrainController.ts` (50 lines), `brain_membership.ts` (4,357 lines), an empty `brain.ts` | `wc -l` | There is no per-app UI pattern yet; a new Apps section is greenfield, and `brain_membership.ts` is the size to avoid repeating |
| **W9** | Databases: `www`, `members`, `auth` share `…_appfactorystudio`; the five apps `coordinator/notes/flow/dream_graph/pdf_editor` share `…_afs_apps_db`. `market` is **not in the table read** | `w/AGENTS.md` §5 | Apps cannot read `brain_*`; they must go through `www`. Where `market` stores data is **unverified** |
| **W10** | **No app sends email today** (stated by the operator). `www` has the whole stack: `afsemail`, `MailHandlerSender`, `BillingMailer`, `laravel_mail_handlers` (per tenant) | this session; `www/app/Mail/*` | Greenfield — no data to migrate. `laravel_mail_handlers` is **per-tenant**, which is why platform handlers need their own table ([03](03-data-model.md)) |
| **W11** | `www` code is deployed to every tenant with the same routes and (empty) `brain_*` tables — but tenants run on **separate servers with their own databases and no access to the Brain's database** (operator statement, 2026-09-25; the topology itself is **unverified in code**) | `plans/billing/08` D9 history | New routes/tables are **present on tenants** and should be absent/inert there (B10). This is hygiene, **not** a takeover risk: a tenant can only ever touch its own empty tables |
| **W12** | Only the Brain writes `brain_*`; `members` writes `members_*` | `w/AGENTS.md` §5 | All new tables are `brain_app_*`, written only by `www` (B5) |

## 1.2 What is missing (the gap this plan fills)

- An **app registry** — the Brain does not know which apps exist, their versions or their status.
- A **manifest** contract — apps cannot declare what is configurable.
- A **configuration store** with validation, history and rollback.
- A **read path** for apps, with caching and a defined failure behaviour.
- A **mail path** for apps — event → handler → send.
- An **admin section** to drive all of the above.

## 1.3 To verify before building (P0)

1. What `brain_users_subapps` and `brain_subapp_coordinator` hold and whether the new registry should extend them.
2. Where `market` keeps its data and whether it can run the same client.
3. Whether a private Composer registry exists for a PHP client, or a copy-and-sync script is the norm (OQ-5).
4. Whether every non-Brain Laravel app has a working cache store (the client's cache depends on it).
5. That `IS_BRAIN=true` is in `_env/{prod,dev,local,vagrant}/.env` and **absent** from `_env/www/.env` (the default tenant file), and that tenant provisioning starts from `_env/www/.env`, not a Brain file (B10, [08 §8.3](08-security-and-safety.md)).
