# 01 — Current State Audit

This design does not start from a blank slate. Before writing the
architecture, the repo was audited for the conventions afs-connect must
follow (or, in one case, fix). This document records what was found, with
file references, so the rest of the plan can be read as "build on X" rather
than restating discovery work.

## 1. The five apps share one physical database — all domain tables are correctly prefixed

Confirmed by reading every migration's actual `Schema::create(...)` call and
every model's `$table` property (not just migration file names — see
"Correction" below):

| App | Table prefix actually used | Notes |
|---|---|---|
| `coordinator` | `pm_*` | Consistent (`pm_projects`, `pm_teams`, `pm_users`, `pm_tasks`, `pm_budgets`, …). |
| `notes` | `notes_*` | Now consistent, including the core table — see "Correction" below. |
| `dream_graph` | `graph_*` | Consistent, including `graph_assets`, `graph_asset_folders`, `graph_entities`, `graph_variable_sets`, `graph_variables`, `graph_templates`, `graph_template_properties`, `graph_texts`, `graph_snapshots`, `graph_playthroughs`, `graph_comments`, `graph_tilesets`, `graph_tilemaps`, `graph_tilemap_layers`, `graph_tilemap_chunks`, `graph_exports`, `graph_imports`, plus the core `graph_projects`/`graph_graphs`/`graph_nodes`/`graph_connections`. |
| `flow` | `flow_*` | Only one table so far (`flow_projects`), consistent. |
| `pdf_editor` | `pefeditor_*` | Consistent (note: not `pdf_editor_*` or `pdfeditor_*` — the actual prefix is `pefeditor_`). |

Laravel's own framework-scaffolding tables (`users`, `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens`)
are intentionally left unprefixed and per-app in every one of the five
apps — these are Laravel infrastructure, not member-facing domain data, and
several of them (`cache`, `jobs`, `sessions`) must keep their default names
for Laravel's queue/cache/session drivers to find them. They are out of
scope for this convention.

> **Correction (superseded an earlier draft of this document):** an earlier
> pass of this audit flagged `dream_graph`'s `assets`, `entities`,
> `variables`, `templates`, `texts`, `snapshots`, `playthroughs`,
> `comments`, `tilesets`, `tilemaps` — and `notes`' `folders` — as
> unprefixed. That was **wrong**: it was inferred from migration **file
> names** (e.g. `create_assets_table.php`), which in `dream_graph` and in
> that one `notes` migration didn't include the prefix even though the
> actual table created inside the file, and the corresponding model's
> `$table` property, already did (e.g. `create_assets_table.php` called
> `Schema::create('graph_assets', ...)`, and `App\Models\Asset` already
> declared `protected $table = 'graph_assets'`). Those thirteen tables
> needed **no table rename at all** — only their misleading migration
> **file names** were fixed (renamed via `git mv`, contents untouched):
>
> - `dream_graph/database/migrations/`: `create_asset_folders_table.php` →
>   `create_graph_asset_folders_table.php`, `create_assets_table.php` →
>   `create_graph_assets_table.php`, `create_entities_table.php` →
>   `create_graph_entities_table.php`, `create_variable_sets_table.php` →
>   `create_graph_variable_sets_table.php`, `create_variables_table.php` →
>   `create_graph_variables_table.php`, `create_templates_table.php` →
>   `create_graph_templates_table.php`, `create_template_properties_table.php`
>   → `create_graph_template_properties_table.php`,
>   `create_texts_table.php` → `create_graph_texts_table.php`,
>   `create_snapshots_table.php` → `create_graph_snapshots_table.php`,
>   `create_playthroughs_table.php` → `create_graph_playthroughs_table.php`,
>   `create_comments_table.php` → `create_graph_comments_table.php`,
>   `create_tilesets_table.php` → `create_graph_tilesets_table.php`,
>   `create_tilemaps_table.php` → `create_graph_tilemaps_table.php`,
>   `create_tilemap_layers_table.php` → `create_graph_tilemap_layers_table.php`,
>   `create_tilemap_chunks_table.php` → `create_graph_tilemap_chunks_table.php`.
> - `notes/database/migrations/`: `create_folders_table.php` →
>   `create_notes_folders_table.php` (the table itself was already
>   `notes_folders`).
>
> **One genuine unprefixed table was found while re-checking, and was
> fixed for real:** `notes`' own core table was actually named `notes`
> (not `notes_notes`) — the one table in that app that doesn't follow the
> `notes_*` convention its own siblings (`notes_tags`, `notes_tag`,
> `notes_links`, `notes_attachments`, `notes_sync_events`, `notes_devices`,
> `notes_folders`) all use. `App\Models\Note` had no explicit `$table`
> property, so Eloquent's default naming (`Note` → `notes`) silently
> matched the equally-unprefixed migration — the app worked, but the table
> name collided with the app's own name-space convention (and would be a
> real collision risk against any future app that also wants a plain
> `notes` table in the shared database). Since `notes` has not been
> migrated against a live database yet, this was a straight rename, not an
> `ALTER TABLE`:
>
> - `notes/database/migrations/2026_07_10_100001_create_notes_table.php` →
>   renamed to `2026_07_10_100001_create_notes_notes_table.php`; its
>   `Schema::hasTable('notes')` / `Schema::create('notes', ...)` /
>   `Schema::dropIfExists('notes')` calls now all reference
>   `'notes_notes'`.
> - `notes/app/Models/Note.php` now explicitly declares
>   `protected $table = 'notes_notes';` (previously relied on Eloquent's
>   default inference, which no longer applies once the table is renamed).
> - Checked and confirmed clean: no other migration in `notes` has a
>   foreign key referencing the old `notes` table (this app defines no DB
>   foreign keys at all — integrity is enforced at the application layer),
>   and no other app in the repo queries `notes`' tables directly (the only
>   established cross-app raw-table-read precedent is the other direction —
>   `notes`/`dream_graph`/`pdf_editor` reading coordinator's `pm_teams`,
>   per §2 below — nothing reads `notes`' own tables from outside `notes`).
>
> All of the above renames were applied via `git mv` (each app is its own
> git repo) and are currently staged, uncommitted, awaiting review/commit
> by the repo owner — no migration content changed beyond the table-name
> strings noted above, and no `ALTER`/backfill statements were written
> anywhere, per instruction, since none of these apps has been migrated
> against a live database yet.

**Implication for afs-connect:** new tables owned by coordinator for this
feature should use their own dedicated prefix — `afsconnect_*` — rather than
`pm_*`, consistent with every other app's convention and because the
feature is conceptually its own module, not PM-domain data. See
[03-database-schema.md](03-database-schema.md).

## 2. There is already a working precedent for one app reading another app's tables directly

`apps/notes`, `apps/dream_graph`, and `apps/pdf_editor` all ship an
identical `App\Services\Settings\TeamSettingsService` that queries
coordinator's `pm_teams`, `pm_team_members`, and `pm_users` tables **directly
via `DB::table(...)`**, from inside a different Laravel app, relying purely
on the shared physical database. Example:
[notes/app/Services/Settings/TeamSettingsService.php](../../../../apps/notes/app/Services/Settings/TeamSettingsService.php).

This proves cross-app raw-table reads are technically available and already
used in production for team/user data. **afs-connect deliberately does not
use this pattern for the aggregation feature itself** (see
[02-architecture.md](02-architecture.md) §"Why an internal API, not raw
cross-DB reads") — but it does mean:

- The precedent for "coordinator's `pm_teams`/`pm_users` is the canonical
  team/user source for all five apps" already exists and should be reused
  for **scoping**, not re-invented. afs-connect's per-team visibility rules
  key off `pm_teams.client_id` / `pm_team_members`, the same tables the
  other apps already read.
- A direct-DB escape hatch remains available later for performance if the
  internal-API approach proves too slow for heavy analytics queries (see
  [07-sync-and-indexing-strategy.md](07-sync-and-indexing-strategy.md) §"Future
  optimization").

## 3. Member identity chain (already built, reused as-is)

Documented inline in `notes/app/Http/Controllers/FunctionsController.php`:

```
Checkpoint JWT `sub` claim
  = ext_member_users.id        (delight-im/auth user table, in Checkpoint's DB)
  = users_member.user_id       (member profile row)
  → users_member.reference_num = member_reference_num
```

Mechanics:

- `FunctionsController::brainCall()` makes an HTTP call to the **brain API** at
  **`BRAIN_API_URL`** from each app's own `.env` (via `brainApiUrl()` — defaults to
  `https://www.appfactory.studio/portal/api/v1/brain` if unset). **Every app**
  in the ecosystem must define `BRAIN_API_URL` in its `.env` file. See also
  [afs-connect-www §06](../afs-connect-www/06-environment-config.md).
- `FunctionsController::getMemberByUserId()` calls `GET /member?user_id=...`
  on the brain and returns the member row (including `reference_num`).
- `FunctionsController::resolveMemberReferenceNum()` wraps that in an
  hour-long cache: `brain.member_reference_num.user_id.{$userId}`.
- A shared `ResolvesMember` trait (present in `notes`, `dream_graph`,
  `pdf_editor`) reads `X-User-Id` header or `user_id` query param from the
  incoming request and calls `resolveMemberReferenceNum()`.
- `coordinator` uses a *different*, simpler trait,
  `ResolvesCoordinatorAuth` ([coordinator/app/Http/Controllers/Concerns/ResolvesCoordinatorAuth.php](../../../../apps/coordinator/app/Http/Controllers/Concerns/ResolvesCoordinatorAuth.php)),
  which trusts `X-Checkpoint-User-Id` / `X-Coordinator-Role` headers set by
  the authenticated browser session rather than re-resolving through the
  brain on every request.

**Implication for afs-connect:** service-to-service calls between apps (the
new internal API) are a different trust boundary than "browser → its own
backend" and must not reuse header-trust. They need their own
signed-request auth — see [06-security-and-service-auth.md](06-security-and-service-auth.md).
Member-scoping *within* each app's own API still uses the existing
`ResolvesMember` / brain pattern unchanged.

## 4. dream_graph does not yet participate in the member_reference_num convention

`coordinator.pm_*`, `notes.notes_notes`, `notes.notes_folders`,
`flow.flow_projects`, and `pdf_editor.pefeditor_documents` all carry a `member_reference_num`
column directly on the row. **`dream_graph.graph_projects` does not** — it
is scoped by `owner_id`, a foreign key to dream_graph's own stock Laravel
`users` table
([dream_graph/app/Models/GraphProject.php](../../../../apps/dream_graph/app/Models/GraphProject.php),
[dream_graph/app/Models/User.php](../../../../apps/dream_graph/app/Models/User.php)),
which is disconnected from Checkpoint identity (no `checkpoint_user_id`, no
`member_reference_num`). dream_graph does have the same `ResolvesMember`
trait and brain-call plumbing as the other apps
([dream_graph/app/Http/Controllers/Concerns/ResolvesMember.php](../../../../apps/dream_graph/app/Http/Controllers/Concerns/ResolvesMember.php)),
so the pieces exist, but nothing currently ties a `graph_project` row to a
`member_reference_num`.

**Implication for afs-connect:** dream_graph needs a small **prerequisite
migration** — add `member_reference_num` (and ideally `checkpoint_user_id`)
to `graph_projects`, backfilled from `owner_id` → brain lookup — before its
`AfsConnect` provider can answer "give me everything for member X." This is
called out explicitly as a Phase 0 blocking task in
[09-rollout-plan.md](09-rollout-plan.md); it is real scope, not a footnote.

## 5. Feature-flag and entitlement infrastructure already exists in Checkpoint

`apps/members/database/migrations/2026_07_13_000001_create_checkpoint_tables.php`
creates, in the **shared members database**:

- `checkpoint_feature_flags` — `feature_key` (unique), `enabled`, `rules`
  (json). A **global** flag store.
- `checkpoint_user_features` — `user_id` + `feature_key` → `enabled`. A
  **per-user override** store.
- `checkpoint_allocations` / `checkpoint_allocation_history` — quota/usage
  tracking, unused by afs-connect v1 but available if usage-based limits
  (e.g. "100 cross-app searches/month on the Starter plan") are wanted
  later.

`apps/auth` already exposes this over HTTP:
`GET /checkpoint/authz/features/{feature}` (per
`CHECKPOINT_DOCUMENTATION.md`).

**Implication for afs-connect:** the global kill switch is **not a new
system** — it is one row, `feature_key = 'afs_connect'`, in an existing
table, read through an existing endpoint. See
[05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md).

## 6. Billing/plan infrastructure already exists in members

`apps/members/app/Models/Externals/Processor/` contains `ProcessorPlan`,
`ProcessorSubscription`, `ProcessorDiscount`, with matching controllers
under `apps/members/app/Http/Controllers/Externals/Processor/` (`ProcessorWebhookController`,
`ProcessorSubscriptionController`, `ProcessorBillingController`, etc.) — a
payment-processor integration (Stripe-shaped) already wired to member
accounts.

**Implication for afs-connect:** plan-level entitlement ("only Pro and above
get afs-connect") is a property to add to `ProcessorPlan`, and the
enable/disable-on-plan-change flow hooks into
`ProcessorWebhookController`'s existing webhook handling rather than
building new billing plumbing. See
[05-feature-toggle-and-billing.md](05-feature-toggle-and-billing.md).

## 7. Known production domains (for the internal-API allowlist)

From `apps/auth/config/checkpoint.php` (`sso.allowed_domains`):

```
coordinator.appfactory.studio      graphs.appfactory.studio
coordinator.dev.appfactory.studio  graphs15.appfactory.studio
www.appfactory.studio              members.appfactory.studio
www15.appfactory.studio            members15.appfactory.studio
notes.dev.appfactory.studio        auth.appfactory.studio
flow.dev.appfactory.studio         auth15.appfactory.studio
pdfeditor.dev.appfactory.studio
```

Note `dream_graph`'s public domain is `graphs(.15).appfactory.studio`, not
`dream-graph` or `dream_graph` — use the real domain in CORS/allowlist
config, not the repo folder name.

## 8. Authorization for sub-users (secondary users) — separate from authentication

Authentication (who is this) and authorization (what can they do) turned
out to be handled very differently, and it matters for afs-connect because
the whole feature depends on scoping cross-app data correctly per user.

### 8.1 Checkpoint only gates *which apps* a sub-user can reach

A secondary user is granted access per app in `checkpoint_secondary_user_apps`
(`secondary_user_id`, `app_name`, `role`, `permissions` JSON) via
`SecondaryUserService::grantApps()`. Checkpoint's own login check
(`canAccessApp()`) only confirms a row exists — it answers "can this
sub-user log into this app at all," never "what can they do once inside."
The `role`/`permissions` values are carried in the login response
(`SecondaryUserService::formatUserData()` → `apps[appName]`) but Checkpoint
itself never interpreted them for authorization decisions.

Separately, `AuthorizationService::can()`/`getUserRoles()` implements real
RBAC (`checkpoint_permissions`, role → action → resource) — but it reads
roles from `roles_mask` on the **primary** `ext_member_users` table only.
`CheckpointService`/`AuthenticationService::getCurrentUserId()` returns
`(int) $decoded->sub` from **any** valid access token without checking
whether it's a primary or secondary token — so before the fix below, if a
secondary user's session called any `/checkpoint/authz/*` endpoint, it
queried the primary users table using the secondary user's own row ID from
`checkpoint_secondary_users` (a completely different ID space). Depending
on whether that ID happened to coincide with an unrelated primary account,
this either silently returned zero roles (permission checks always false)
or, worse, returned a coincidentally-matching stranger's roles. This was a
live, real bug, not a hypothetical.

### 8.2 Two unrelated files were both called "authz.ts"

The confusion this caused is worth recording since it's exactly why the
first read of this looked more consistent than it was:

- **`resources/js/checkpoint/authz.ts`** — part of the shared Checkpoint
  client SDK, present verbatim in all 8 apps (`coordinator`, `notes`,
  `dream_graph`, `flow`, `pdf_editor`, `members`, `www`, `auth`). This is
  "the checkpoint authz.ts api." Before the fix, it only handled **primary**
  users (via the broken `/authz/*` endpoints above) and additionally
  depended on Flagsmith for feature flags — a dead dependency, since
  feature flags are actually served by Checkpoint's own
  `checkpoint_feature_flags`/`checkpoint_user_features` tables (§5); the
  Flagsmith init only "fell back" to that same server check on failure,
  meaning it was doing nothing except adding a network call, a 3rd-party
  script dependency, and dead code paths.
- **`coordinator/resources/js/src/authz.ts`** and each of
  `dream_graph`/`flow`/`notes`/`pdf_editor`'s
  `resources/js/settings/authz.ts` — small (~50 line), app-specific,
  independently-written helpers that read the secondary user's role
  straight off `app.window.user.apps[APP_NAME]?.role` (this data really is
  delivered correctly at login, per §8.1 — nothing wrong with it) and stuff
  it into the `X-Coordinator-Role`/`X-App-Role` header the backend then
  trusts blindly (§3). Every app had its own copy of this, inconsistently
  named (`getCoordinatorRoleForApi()` vs `getAppRoleForApi()`,
  `CHECKPOINT_APPS.COORDINATOR` vs a `SETTINGS_APP_NAME` constant).

So there were, in effect, two parallel and disconnected authorization
systems: a "real" one (the SDK, JWT-backed, extensible) that didn't work
for secondary users, and a working-but-ad-hoc one (custom headers) that
bypassed the SDK entirely and that every app reimplemented slightly
differently.

### 8.3 Per-app enforcement maturity, before any of this was touched

| App | Mechanism |
|---|---|
| `coordinator` | `X-Coordinator-Role` header (admin vs member); non-admins scoped to their own `pm_users.client_id` via `pm_team_members`; team edits admin-only. |
| `flow` | Identical pattern under a different name (`X-App-Role`, `isAppAdmin()`). |
| `pdf_editor` | Not role-based — per-document ACL via `pefeditor_document_shares` (owner + explicit share grants + public share tokens). A legitimately different, valid model, not a gap. |
| `notes` | No ACL beyond `member_reference_num`. Any user resolving to that member account sees/edits every note — no team or per-note restriction. |
| `dream_graph` | Weakest: a `GraphProjectPolicy` (ownership-based) is registered via `Gate::policy()` but never invoked. The live `ProjectController` has literal `// Authorization disabled for development` comments on every action, and `index()` returns every project in the table with no member scoping at all — worse than the missing-`member_reference_num` gap already noted in §4, and currently live. |

### 8.4 What was fixed in code (this session), and what's still open

**Fixed and applied** (staged via `git mv`/edits in each app's own repo,
uncommitted, pending review — not yet deployed):

- `resources/js/checkpoint/authz.ts` rewritten in all 8 apps: Flagsmith
  removed entirely; added a `setAuthContext(userType, appName, user)`
  method that resolves a secondary user's role/permissions directly from
  the `apps[appName]` grant already present in the session (no network
  call, no dependency on the broken primary-only server endpoints). `can()`
  / `getRoles()` / `hasRole()` / `getProfile()` now work correctly for
  secondary users; primary-user behavior is unchanged.
- `resources/js/checkpoint/client-handler.ts` (the 6 apps sharing an
  identical baseline: `coordinator`, `dream_graph`, `flow`, `notes`,
  `pdf_editor`, `members`) now calls `setAuthContext()` automatically
  wherever a session is resolved, and clears it on logout — so apps using
  `ClientHandler` get this for free with no per-app wiring.
- Server-side Flagsmith removed: `AuthorizationController::getFlagsmithConfig()`,
  its route (`GET /checkpoint/authz/flagsmith/config`), the `'flagsmith'`
  block in `config/checkpoint.php`, and the `flagsmith` npm dependency in
  all 8 `package.json` files (kept `accesscontrol`, still used for RBAC).

**Deliberately not done in this pass** (bigger, separate work):

- **The X-Coordinator-Role/X-App-Role header-trust pattern itself is still
  in place.** Each app's PHP controllers still read a client-supplied
  header instead of calling the now-fixed `authz.ts`-backed checks
  server-side. The small `src/authz.ts` / `settings/authz.ts` role-derivation
  helpers per app still exist and still feed those headers. Migrating every
  app's controllers to a real, server-verified permission check (rather
  than trusting a header) is the actual "make authorization uniform"
  end-state implied by this whole investigation, but it's a cross-cutting
  change to every controller in five apps and deserves its own reviewed
  pass, not a rushed side-effect here. **This is now a required Phase 0
  item** — see [09-rollout-plan.md](09-rollout-plan.md).
- `dream_graph`'s disabled `ProjectController` authorization (§8.3) — not
  patched; it should be fixed as part of the header-trust migration above,
  not bespoke.
- `notes`' missing team/per-note ACL (§8.3) — same; a design decision
  (should notes gain team scoping at all, or is member-wide access
  intentional for a personal notes tool?) that needs a product answer
  before code, not something to guess at silently.
- A structurally identical ID-collision risk exists in
  `checkpoint_user_features` (feature-flag overrides are keyed by a bare
  `user_id` column with no primary/secondary discriminator, same as the
  roles bug in §8.1) — not fixed, flagged for awareness. Lower priority
  than the roles bug because it degrades a feature-flag override, not a
  permission check.
- The canonical downloadable SDK under
  `apps/auth/public/checkpoint/client/typescript/src/` (the `@checkpoint/client`
  package documented in `CHECKPOINT_DOCUMENTATION.md`) is itself stale
  relative to what the 8 apps actually run — e.g. its `secondary-users.ts`
  is missing the `apps`/`app_names` fields the deployed copies already
  have. Left untouched; syncing the publishable SDK is a separate concern
  from fixing what's actually running.

**Implication for afs-connect:** afs-connect's own team/permission scoping
(§06) can now be built on a working `authz.ts` for secondary users, but it
still inherits whatever each source app's controllers actually enforce
today (§8.3) until the header-trust migration above happens. Treat that
migration as a hard Phase 0 dependency, not a nice-to-have — afs-connect
aggregating data through still-header-trusting, inconsistently-scoped
source apps would just make the existing gaps (especially `notes` and
`dream_graph`) visible to more of the account than before.

## Summary of prerequisites this audit adds to the plan

0. **Done already, not a future task:** `notes`' core table was renamed
   `notes` → `notes_notes` (migration + model updated, migration file
   renamed) so every domain table in every one of the five apps now
   follows its app's prefix convention with no exceptions. `dream_graph`'s
   migration file names were also cleaned up to match the (already
   correct) table names inside them. Both sets of renames are staged via
   `git mv` in their respective app repos, uncommitted, pending the repo
   owner's review — see §1 above for the full list.
1. New coordinator tables use the `afsconnect_` prefix (not `pm_`).
2. Team/visibility scoping reuses `pm_teams` / `pm_team_members` as the
   canonical source, consistent with existing cross-app reads.
3. Service-to-service calls get their own auth layer; browser-facing
   header-trust is not extended to app-to-app calls.
4. **dream_graph needs a `member_reference_num` backfill migration** before
   it can be onboarded as a source app (Phase 0 blocker).
5. The global kill switch is one `checkpoint_feature_flags` row, not new
   infrastructure.
6. Plan-level entitlement extends `ProcessorPlan`; enforcement hooks into
   the existing `ProcessorWebhookController`.
7. Use real production subdomains (`graphs.appfactory.studio` for
   dream_graph, etc.) everywhere env/config examples are given.
8. **Sub-user authorization fix, partially done.** The `authz.ts` SDK +
   Flagsmith removal is applied (§8.4) but each app's controllers still
   trust the `X-Coordinator-Role`/`X-App-Role` header instead of a real
   server-side check — migrating that is a hard Phase 0 dependency for
   afs-connect, not optional cleanup (§8.4).
9. Config for all five apps + `members` + `www` lives in each app's own `.env` —
   including **`BRAIN_API_URL`** for Brain API access in every app. `apps/afsserver`
   is unrelated to runtime configuration (corrects an earlier draft of this plan).
