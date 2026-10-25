# Migration consolidation — scope

Status: SCOPE ONLY. No migration has been changed. Generated from a read of `w/www/database/migrations` (96 files).

## 1. Result of the audit

| Kind | Files | Meaning |
|---|---|---|
| Create-only | 58 | already one file per table group; keep (rename only where the name is unclear) |
| **Pure alter** (only `Schema::table` / raw `ALTER`) | 19 | fold into the file that creates the table, then delete |
| **Alter by loop** (`TARGETS` array, no literal table name) | 2 | `..._000011_add_site_user_reference...` and `..._000000_drop_site_user_id...`: they add then remove the same kind of column on 2 consumer tables; net result must be computed |
| **Index-only** | 2 | `..._000002_add_domain_claim_unique_indexes`, `..._000004_add_publish_and_deploy_unique_indexes`: fold the unique indexes into the create files |
| **Mixed** (new tables + alters of other tables) | 15 | keep the new tables, move the alters into the owning create file |
| Misc | 1 | `create_configured_queue_jobs_table` creates by variable name (fine) |

Two earlier consolidations already exist (`create_laravel_direct_payment_tables` holds 13 tables, `create_brain_tables`
holds 17, `create_processor_tables` 17, `create_ext_users_tables` 16): their headers list the original migrations
they absorbed. This scope continues the same direction.

## 2. Pure-alter files and where each folds

| File to delete | Table(s) altered | Fold into (the file that creates the table) | Notes |
|---|---|---|---|
| `2026_09_01_000001_add_publish_manifest_to_builder_published.php` | `laravel_builder_published` | `2026_04_19_000001_create_laravel_builder_tables.php` |  |
| `2026_09_03_150000_add_ecommerce_inventory_storefront_columns.php` | `laravel_ecommerce_stores` | `2026_04_03_133338_create_laravel_ecommerce_tables.php` |  |
| `2026_09_03_220000_add_ecommerce_order_storefront_columns.php` | `laravel_ecommerce_orders` | `2026_04_03_133338_create_laravel_ecommerce_tables.php` | data backfill / raw SQL in `up()`: not needed (no data) |
| `2026_09_06_000000_add_ecommerce_facade_payment_refs.php` | `laravel_ecommerce_order_lines`, `laravel_ecommerce_orders`, `laravel_ecommerce_stores` | `2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php`; `2026_04_03_133338_create_laravel_ecommerce_tables.php`; `2026_04_03_133338_create_laravel_ecommerce_tables.php` | drops columns in `up()`: compute NET schema; data backfill / raw SQL in `up()`: not needed (no data) |
| `2026_09_09_000000_events_facade_payment_refs.php` | `laravel_event_orders`, `laravel_event_registrations` | `2026_08_31_200000_create_events_module_tables.php`; `2026_08_31_200000_create_events_module_tables.php` | drops columns in `up()`: compute NET schema; data backfill / raw SQL in `up()`: not needed (no data) |
| `2026_09_13_000000_add_payer_reference_num_to_ecommerce_tables.php` | `laravel_ecommerce_carts`, `laravel_ecommerce_orders` | `2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php`; `2026_04_03_133338_create_laravel_ecommerce_tables.php` |  |
| `2026_09_13_000001_add_payer_reference_num_to_event_orders.php` | `laravel_event_orders` | `2026_08_31_200000_create_events_module_tables.php` |  |
| `2026_09_13_000002_add_payer_reference_num_to_appointment_bookings.php` | `laravel_appointment_bookings` | `2026_04_13_000001_create_laravel_appointments_table.php` |  |
| `2026_09_14_000001_add_site_user_reference_to_site_user_payers.php` | `laravel_site_user_payers` | `2026_09_13_000003_create_site_user_payers_table.php` | data backfill / raw SQL in `up()`: not needed (no data) |
| `2026_09_16_000001_add_active_cart_token_to_ecommerce_carts.php` | `laravel_ecommerce_carts` | `2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php` | drops columns in `up()`: compute NET schema; data backfill / raw SQL in `up()`: not needed (no data) |
| `2026_09_19_000001_add_egress_columns_to_members_communications_recordings.php` | `members_communications_recordings` | `2026_09_18_000001_create_members_communications_tables.php` |  |
| `2026_09_22_000002_add_payer_reference_num_to_brain_memberships.php` | `brain_memberships` | `2026_05_15_000001_create_brain_tables.php` | drops columns in `up()`: compute NET schema |
| `2026_09_23_000001_replace_member_reference_num_with_payer_on_direct_payments.php` | `laravel_direct_payment_notification_sends`, `laravel_direct_payment_subscriptions` | `2026_04_03_160000_create_laravel_direct_payment_tables.php`; `2026_04_03_160000_create_laravel_direct_payment_tables.php` | drops columns in `up()`: compute NET schema |
| `2026_09_23_000002_add_tier_source_to_brain_memberships.php` | `brain_memberships` | `2026_05_15_000001_create_brain_tables.php` |  |
| `2026_09_23_000003_add_actor_and_reason_to_brain_membership_events.php` | `brain_membership_events` | `2026_05_15_000001_create_brain_tables.php` |  |
| `2026_10_05_000001_add_notice_columns_to_members_email_band_usage.php` | `members_email_band_usage` | `2026_09_22_000001_create_members_email_retention_tables.php` |  |
| `2026_10_07_000001_add_result_to_brain_infra_audit.php` | `brain_infra_audit` | `2026_10_05_000001_create_brain_infra_audit_and_history_tables.php` |  |
| `2026_10_08_000006_add_deployment_runner_columns.php` | `brain_website_deployments` | `2026_10_08_000003_add_publish_and_deploy_data_model.php` |  |
| `2026_10_09_000001_add_payment_refs_to_domain_purchases.php` | `brain_domain_purchases` | `2026_10_08_000005_add_purchase_tables_and_selection_columns.php` |  |

### Loop / index-only files

| File | What it does | Fold into |
|---|---|---|
| `2026_09_13_000011_add_site_user_reference_to_consumer_tables.php` | adds `site_user_reference` (+ index) to a `TARGETS` list of consumer tables | the create files of those tables (read `TARGETS`) |
| `2026_09_14_000000_drop_site_user_id_from_consumer_tables.php` | drops the old `site_user_id` column and indexes from two consumer tables | remove `site_user_id` from those tables' create files |
| `2026_10_08_000002_add_domain_claim_unique_indexes.php` | 2 unique indexes on `brain_dns_zones` (`active_fqdn`, `member_live_key`, both generated columns) | `brain_dns_zones` in `create_brain_tables` |
| `2026_10_08_000004_add_publish_and_deploy_unique_indexes.php` | unique indexes on the publish/deploy tables; its header says it must run after `domains:backfill-tenants --apply` | the create files of those tables (that ordering reason disappears with no data) |

## 3. Mixed files (new tables stay, alters move)

| File | New tables it keeps | Alters to move out | Move into |
|---|---|---|---|
| `2026_08_31_120000_create_ecommerce_tax_and_shipping_tables.php` | `laravel_ecommerce_tax_zones`, `laravel_ecommerce_tax_rates`, `laravel_ecommerce_shipping_zones`, `laravel_ecommerce_shipping_methods`, `laravel_ecommerce_shipping_rates` | `laravel_ecommerce_carts`, `laravel_ecommerce_stores`, `laravel_ecommerce_variants` | `laravel_ecommerce_carts` → `2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php`; `laravel_ecommerce_stores` → `2026_04_03_133338_create_laravel_ecommerce_tables.php`; `laravel_ecommerce_variants` → `2026_04_03_133338_create_laravel_ecommerce_tables.php` |
| `2026_08_31_140000_create_ecommerce_promotions_tables.php` | `laravel_ecommerce_promotions`, `laravel_ecommerce_promotion_redemptions`, `laravel_ecommerce_gift_cards`, `laravel_ecommerce_gift_card_transactions` | `laravel_ecommerce_carts`, `laravel_ecommerce_stores` | `laravel_ecommerce_carts` → `2026_09_02_061800_create_ecommerce_cart_and_order_annex_tables.php`; `laravel_ecommerce_stores` → `2026_04_03_133338_create_laravel_ecommerce_tables.php` |
| `2026_08_31_200000_create_events_module_tables.php` | `laravel_event_occurrences`, `laravel_event_ticket_types`, `laravel_event_promo_codes`, `laravel_event_orders`, `laravel_event_registrations` | `laravel_events` | `laravel_events` → `2026_07_23_000001_create_laravel_events_tables.php` |
| `2026_08_31_210000_create_events_extended_tables.php` | `laravel_venues`, `laravel_event_sessions`, `laravel_event_ticket_tiers`, `laravel_event_holds`, `laravel_event_waitlist`, `laravel_event_checkins`, `laravel_event_people`, `laravel_event_activity`, `laravel_event_passes` | `laravel_events` | `laravel_events` → `2026_07_23_000001_create_laravel_events_tables.php` |
| `2026_08_31_230000_create_appointments_extended_schema.php` | `laravel_appointment_services`, `laravel_appointment_schedules`, `laravel_appointment_staff`, `laravel_appointment_holds`, `laravel_appointment_slot_counters` | `laravel_appointment_bookings`, `laravel_appointments` | `laravel_appointment_bookings` → `2026_04_13_000001_create_laravel_appointments_table.php`; `laravel_appointments` → `2026_04_13_000001_create_laravel_appointments_table.php` |
| `2026_08_31_232000_create_appointments_step08_tables.php` | `laravel_appointment_waitlist`, `laravel_appointment_series`, `laravel_appointment_credits` | `laravel_appointment_bookings`, `laravel_appointment_services` | `laravel_appointment_bookings` → `2026_04_13_000001_create_laravel_appointments_table.php`; `laravel_appointment_services` → `2026_08_31_230000_create_appointments_extended_schema.php` |
| `2026_08_31_233000_create_appointment_calendar_connections.php` | `laravel_appointment_calendar_connections` | `laravel_appointment_services` | `laravel_appointment_services` → `2026_08_31_230000_create_appointments_extended_schema.php` |
| `2026_09_03_210000_add_ecommerce_save_later_and_favorites.php` | `laravel_ecommerce_saved_for_later`, `laravel_ecommerce_favorites` | `laravel_ecommerce_stores` | `laravel_ecommerce_stores` → `2026_04_03_133338_create_laravel_ecommerce_tables.php` |
| `2026_09_13_000003_create_site_user_payers_table.php` | `laravel_site_user_payers` | `laravel_site_user_modules` | `laravel_site_user_modules` → `2026_08_31_180000_create_site_users_schema.php` |
| `2026_09_20_000001_create_members_communications_breakout_rooms_table.php` | `members_communications_breakout_rooms` | `members_communications_participants` | `members_communications_participants` → `2026_09_18_000001_create_members_communications_tables.php` |
| `2026_09_27_000003_create_members_storage_placements.php` | `members_storage_placements` | `members_storage_nodes`, `members_storage_sessions` | `members_storage_nodes` → `2026_08_23_000001_create_members_storage_tables.php`; `members_storage_sessions` → `2026_08_23_000001_create_members_storage_tables.php` |
| `2026_10_08_000001_add_domain_claim_columns_and_tables.php` | `brain_site_refs`, `brain_domain_challenges`, `brain_domain_events` | `brain_dns_zones` (+ raw SQL) | `brain_dns_zones` → `2026_05_15_000001_create_brain_tables.php` |
| `2026_10_08_000003_add_publish_and_deploy_data_model.php` | `brain_dns_publish_jobs`, `brain_dns_publish_targets`, `brain_website_deployments`, `brain_deploy_steps` | `brain_dns_subdomains`, `brain_dns_zones`, `brain_http_server_tenants`, `brain_http_servers` (+ raw SQL) | `brain_dns_subdomains` → `2026_05_15_000001_create_brain_tables.php`; `brain_dns_zones` → `2026_05_15_000001_create_brain_tables.php`; `brain_http_server_tenants` → `2026_05_15_000001_create_brain_tables.php`; `brain_http_servers` → `2026_05_15_000001_create_brain_tables.php` |
| `2026_10_08_000005_add_purchase_tables_and_selection_columns.php` | `brain_domain_quotes`, `brain_domain_purchases` | `brain_http_servers` (+ raw SQL) | `brain_http_servers` → `2026_05_15_000001_create_brain_tables.php` |
| `2026_10_11_000001_add_enrollment_to_brain_storage_servers.php` | `brain_storage_enrollment_attempts`, `brain_storage_server_events`, `brain_storage_discovered_devices` | `brain_storage_servers` (+ raw SQL) | `brain_storage_servers` → `2026_09_27_000002_create_brain_storage_tables.php` |

## 4. Work that is not just copy-and-paste

1. **Net schema, not sum of edits.** Some alters add a column that a later alter drops (`facade_payment_refs`
   for ecommerce and events, `drop_site_user_id`, `replace_member_reference_num_with_payer_on_direct_payments`).
   The create file must end up with the final column set only.
2. **Raw SQL.** Generated columns and defaults are written as `DB::statement('ALTER TABLE ... GENERATED ...')`
   in the Brain domain files (`brain_dns_zones`, `brain_dns_subdomains`, `brain_http_server_tenants`,
   `brain_website_deployments`, `brain_http_servers`). In a create file these become Blueprint
   `->virtualAs()/->storedAs()` columns, or stay as a `DB::statement` after the create.
3. **Data backfills are dropped.** `UPDATE ... SET placed_at = created_at`-style statements in
   `add_ecommerce_order_storefront_columns`, `add_active_cart_token_to_ecommerce_carts`,
   `add_enrollment_to_brain_storage_servers`, the `state`/`accepts_tenants` updates in the Brain domain files, and
   `add_site_user_reference_to_site_user_payers`. With no data they do nothing.
4. **Guards.** The files use `Schema::hasTable` / `hasColumn` guards. The new create files keep the table-level
   `hasTable` guard only.
5. **Better names.** The `step04`, `step08`, `extended`, `facade` names come from plan step numbers. Proposed:
   one file per module and table group, named for the module (for example `create_laravel_ecommerce_tables`
   already; `create_laravel_events_tables` absorbs `events_module` and `events_extended`;
   `create_laravel_appointments_tables` absorbs the three appointments files).
6. **File order.** A create file can only add a foreign key to a table created earlier. Folding an alter into an
   earlier create file is safe only if the referenced table exists by then; each such case is listed when the file
   is changed.

## 5. How to prove nothing changed

Before touching anything, on a machine with PHP and MySQL:

```
php artisan migrate:fresh
mysqldump --no-data --skip-comments <db> > /tmp/schema_before.sql
```

After consolidation, again `migrate:fresh` into an empty database and dump to `/tmp/schema_after.sql`.
`diff` of the two (after sorting tables) must be empty, apart from the deliberate changes listed in the plan (for
example dropped dead columns). The test suite (`RefreshDatabase`) runs the same migrations and is the second check.
`php` is not installed on this PC, so this runs on the server or another machine.

## 6. Operational notes

* The dev server database already has these migrations recorded in its `migrations` table. After consolidation it
  must be rebuilt (`migrate:fresh`); this needs the owner's approval per the server agent's rules.
* Roughly 160 mentions of migration filenames appear in `packages/afsserver/plans/**` and a few in code comments.
  They become stale; they do not affect behavior.
* `w/www` is the only project scoped here. The other apps (`auth`, `members`, `coordinator`, ...) have their own
  `database/migrations` folders and were not audited.

## 7. Decisions (from the owner)

* Keep the **date** part of file names (a merged file keeps the earliest date among the files it absorbs). The part
  after the date may be renamed to say what is inside.
* Drop dead columns found while computing the net schema.
* As few files as possible: alters fold into their create file, and related create files are combined by module.
* Framework files stay as they are.
* All `brain_` tables go into one file.
* Mail handlers, `users_email` and market notifications each keep their own file.
* `feedback_submissions` is a module table. `websocket_failure_events` belongs to chat.
* The systems-users tables are dropped, together with their code; `users_member` is dropped too, with every
  reference to it. The audit is in [01-legacy-removals.md](01-legacy-removals.md).
* `subdomain_properties` gets its own file; `integrations`, `notifications`, `settings`, `documents` and
  `progress_records` are combined into one platform file.
* `laravel_brain_alloc_audit` and `laravel_brain_alloc_overrides` go in the Brain file.

## 8. Checked against the code

| Owner's point | What the code shows | Plan |
|---|---|---|
| systems users is gone, replaced by site users | The tables are still read by two controllers (`app/Http/Controllers/Externals/SystemsUsers/SystemsUsersPublicController.php`, `SystemsUsersAdminController.php`), two models (`app/Models/Externals/SystemsUsers/*`) and a legacy route group guarded by `SiteUsersDeprecationLog`. `2026_08_31_180000_create_site_users_schema.php` (lines ~207-265) copies data out of them and has a deprecation table. | Drop both tables and remove the code that uses them: the 2 controllers, 2 models, the legacy `systemsusers` routes, `RoutePolicy` entries, and the data-copy block in the site users migration. Needs the owner's OK because it is code removal, not only migrations. |
| `users_member` is no longer used | It is still referenced: `www/app/Http/Controllers/FunctionsController.php` lines 368, 3429, 3447, 3459 (raw SQL on `users_member`) and the `coordinator` app's `FunctionsController` reads it (member by reference, user id, email). | Owner decision: drop it with every reference. Audit in [01-legacy-removals.md](01-legacy-removals.md); the legacy front-end that still calls it has to go first. |
| `websocket_failure_events` belongs to chat | `WebsocketFailureController` is under `Externals/Chat`; `ChatService.ts` posts `reason: 'websocket_failure'`. | Move into the chat file. The table name stays (it is in `MigrationOwnershipTest`'s legacy list). |
| `feedback_submissions` is a module table | `FeedbackController` is under `Externals/Feedback`. | Move into the site content modules file. |
| market notifications own file | `members_market_notifications` is used only by the `members` app (`members/app/Models/MarketNotification.php`). | Own file, name must contain `_create_members_` (see below). |

### A test that constrains the layout

`www/tests/Unit/Database/MigrationOwnershipTest.php` fails the build if:

1. a table without a known prefix is created (legacy exceptions: `cache`, `cache_locks`, `failed_jobs`, `job_batches`,
   `jobs`, `migrations`, `password_reset_tokens`, `sessions`, `users`, `progress_records`, `subdomain_properties`,
   `users_email`, `users_member`, `websocket_failure_events`);
2. one table is created by more than one migration;
3. two migrations share the same name after the date;
4. a `members_` table is declared in a file whose name does not contain `_create_members_`.

So every file that holds `members_` tables keeps `_create_members_` in its name, and the merged names must stay unique.

## 9. Target layout: 96 files → 26

"Absorbs" lists the current files that disappear into it. Names after the date are new where marked.

| # | Target file | Tables | Absorbs |
|---|---|---|---|
| 1 | `0001_01_01_000000_create_users_table` | users, password_reset_tokens, sessions, user_tokens | — (stays) |
| 2 | `0001_01_01_000001_create_cache_table` | cache, cache_locks | — (stays) |
| 3 | `0001_01_01_000002_create_jobs_table` | jobs, job_batches, failed_jobs | — (stays) |
| 4 | `2026_09_24_000001_create_configured_queue_jobs_table` | configured queue jobs | — (stays) |
| 5 | `2026_04_07_211626_create_mail_handlers_table` | mail_handlers, mail_providers | — (stays) |
| 6 | `2026_05_11_000001_create_users_email_table` | users_email | — (stays) |
| 7 | `2026_05_04_051958_create_sub_domain_properties_table` **(renamed, own file)** | subdomain_properties | — |
| 8 | `2026_08_19_000001_create_members_market_notifications_table` | members_market_notifications | — (stays) |
| 9 | `2026_04_14_000001_create_laravel_platform_tables` **(renamed)** | integrations (2), notifications, settings, documents, progress_records | `2026_04_23_000001`, `2026_04_24_000001`, `2026_05_04_000002`, `2026_06_16_000002` |
| 10 | `2026_03_15_000012_create_laravel_chat_schema` | chat (5) + websocket_failure_events | `2026_03_11_000008` |
| 11 | `2026_04_03_133338_create_laravel_ecommerce_tables` | all `laravel_ecommerce_*` | `2026_08_31_120000`, `2026_08_31_140000`, `2026_09_02_061800`, `2026_09_03_050000`, `2026_09_03_150000`, `2026_09_03_210000`, `2026_09_03_220000`, `2026_09_06_000000`, `2026_09_13_000000`, `2026_09_15_000000`, `2026_09_16_000001` |
| 12 | `2026_04_03_160000_create_laravel_direct_payment_tables` | direct payments (13) + default methods | `2026_09_16_000000`, `2026_09_23_000001` |
| 13 | `2026_04_04_000001_create_laravel_site_content_tables` **(renamed)** | feedback_submissions, listings (2), subscribers (5), sponsorships (4), analytics (2) | `2026_04_04_120000`, `2026_04_05_133000`, `2026_04_26_000001`, `2026_05_02_000001` |
| 14 | `2026_04_11_000001_create_laravel_forms_tables` | forms (8) | — |
| 15 | `2026_04_13_000001_create_laravel_appointments_tables` **(renamed)** | all `laravel_appointment*` | `2026_08_31_230000`, `2026_08_31_231000`, `2026_08_31_232000`, `2026_08_31_233000`, `2026_09_13_000002` |
| 16 | `2026_04_19_000001_create_laravel_builder_tables` | builder output/projects/published/module_refs/routes | `2026_09_01_000001`, `2026_09_01_000002` |
| 17 | `2026_05_15_000001_create_brain_tables` | **every `brain_` table**: config, DNS, HTTP servers/tenants/clusters, subapps, memberships (+ checkpoint errors, billing state, events, billing settings), apps (registry, settings, mail, definitions), storage (servers, devices, keys, enrollment), device tokens, infra audit/history, domain claim/publish/deploy/purchase. Also the two `laravel_brain_alloc_*` tables (Brain control plane; their prefix is not `brain_`, ask below). About 55 tables. | `2026_08_28_000001`, `2026_09_22_000002`, `2026_09_23_000002`, `2026_09_23_000003`, `2026_09_26_000001`…`000003`, `2026_09_27_000001`, `2026_09_27_000002`, `2026_09_27_000004`, `2026_10_04_000001`, `2026_10_05_000001_create_brain_infra…`, `2026_10_07`, `2026_10_08_000001`…`000006`, `2026_10_09`, `2026_10_11` |
| 18 | `2026_05_15_000003_create_members_website_tables` | members_websites, deployment progress, members_user_files | `2026_01_22_000000` |
| 19 | `2026_08_23_000001_create_members_storage_tables` | members storage (nodes, usage, sessions, files, retention, placements, operations) | `2026_09_27_000003`, `2026_09_27_000005` |
| 20 | `2026_09_18_000001_create_members_communications_tables` | rooms, sessions, participants, recordings, turn servers, breakout rooms | `2026_09_19_000001`, `2026_09_20_000001` |
| 21 | `2026_09_22_000001_create_members_email_retention_tables` | email band usage, retention events | `2026_10_05_000001_add_notice…` |
| 22 | `2026_09_28_000001_create_members_calendar_tables` | calendar records/seq/devices, shares, invites | `2026_09_29_000001` |
| 23 | `2026_05_21_000001_create_processor_tables` | processors (17) | — |
| 24 | `2026_06_10_000001_create_ext_users_tables` | ext users (16) | — |
| 25 | `2026_07_23_000001_create_laravel_events_tables` | events, venues, occurrences, tickets, orders, registrations, sessions, holds, waitlist, checkins, people, activity, passes, redemptions | `2026_08_31_200000`, `2026_08_31_210000`, `2026_08_31_220000`, `2026_09_09_000000`, `2026_09_13_000001` |
| 26 | `2026_08_31_180000_create_site_users_schema` | site users (10) + payers, connections, mode snapshots, admin actions | `2026_09_13_000003`, `2026_09_13_000010`, `2026_09_13_000011`, `2026_09_14_000000`, `2026_09_14_000001`, `2026_09_14_000002` |

Dropped outright: `2026_04_02_000001_create_laravel_systemsusers_tables` and `2026_07_10_000001_create_users_member_table` (the tables are removed together with their code; see [01-legacy-removals.md](01-legacy-removals.md)).

Foreign-key order is the one thing that can go wrong in a merge. A table only moves into a file dated earlier than or
equal to the files that reference it. Checked per row when each file is edited.

## 10. How the work would run

1. Baseline: before anything changes, run `migrate:fresh` on the dev server against a scratch database using the
   current migrations and dump the table structure (`mysqldump --no-data`).
2. Merge one module at a time (rows 11, 12, 15, 25, 26, 17 first: they hold most of the alters).
3. After each module, run `migrate:fresh` into the scratch database and diff against the baseline. Differences must
   be exactly the dead columns dropped on purpose, listed with the change.
4. Delete the absorbed files only after their diff is clean.
5. Update `MigrationOwnershipTest` only if a rule must change (it should not).
6. Rebuild the real dev database with `migrate:fresh` at the end (needs the owner's approval).

Access check done on the server: `mysql` 8.0.41 is installed, `root` over the socket needs a password, so the
scratch database has to be created with the project's own database credentials from `www/.env`. Whether that user
may `CREATE DATABASE` has not been checked.

## 11. Server database check (SHOW GRANTS, read-only, repeated after the owner changed the privileges)

The Laravel database user on the dev server is `appfactorystudio`@`localhost`. It still has **no global `CREATE`**
(`GRANT USAGE ON *.*`), so it cannot create a database. It now has `ALL PRIVILEGES` on 21 named databases, including
`testdb_appfactorystudio` (the `DB_DATABASE` in the dev `.env`).

Tables per granted database (read from `information_schema`):

| Tables | Databases |
|---|---|
| 0 | `afs_apps_db`, `bookstack_immi_db` |
| 3-13 | `feature_builder_mcp` (3), `afsdb_identity_provider` (9), `laravel_afs_db` (11), `testdb_afs_idp` (11), `laravel_afs_market2_db` (13), `laravel_afs_market_db` (13) |
| 37-81 | `afsdb_appfactorystudio` (37), `bookstack_afs_db` (40), `testdb_afs_market` (45), `appfactorystudiodb` (73), `afsdb_apps` (74), `testdb_afs_apps` (81) |
| 257 | `testdb_appfactorystudio` |

So a scratch database can be an existing **empty** one: `afs_apps_db` or `bookstack_immi_db`. `migrate:fresh` against it
would drop every table in it, which is nothing today, and I would drop the tables again afterwards. I could not
confirm that no application points at either name (a recursive search on the server timed out), so that is the one
thing to settle before using one.

## 12. Baseline taken (2026-10-14)

* Grants now in place for `appfactorystudio`@`localhost`: `CREATE, DROP ON *.*` and `ALL PRIVILEGES ON
  migration\_scratch\_%.*`. The scratch database is `migration_scratch_base`; nothing else is touched.
* The 96 current migrations (uploaded from `w/www/database/migrations` to `/tmp/migr_before/migrations` on the
  server) were run with `DB_DATABASE=migration_scratch_base php artisan migrate --force --realpath --path=...`.
  All 96 are recorded. It takes about 3 minutes.
* Result: **247 tables.** (`testdb_appfactorystudio` has 257, because the dev database is older or holds extra
  tables; it is not the reference.)
* Structure dump: `/tmp/schema_before.sql` on the server (5131 lines, `mysqldump --no-data --skip-comments
  --skip-dump-date --no-tablespaces`, `AUTO_INCREMENT=n` removed), copy on the PC at
  `/private/tmp/claude-501/.../scratchpad/schema_before.sql`.
* `migrate:fresh` is not used on the server: Laravel's table listing there spans every schema the user can see, so
  the scratch database is reset with `DROP DATABASE` + `CREATE DATABASE` instead.

## 13. Result of the consolidation (2026-10-14)

* 96 migration files became **28**. The original 96 are backed up in `packages/afsserver/tmp/migrations_backup_2026-10-14`.
* Method: `migtool.py` (kept in the session scratchpad) merged each module. Tables that no other migration touched
  were copied as they were; tables other migrations altered were regenerated from the baseline dump, so they hold the
  final columns and indexes only. Data backfills and the legacy-data copy blocks were dropped.
* Check: `bin/verify-migrations.sh` runs the 28 files on the dev server into `migration_scratch_after` and diffs the
  structure against the baseline with `bin/schema_diff.py`. Result: **247 tables before, 247 after, 0 differences.**
  All 28 files also pass `php -l` on the server.
* The two things the first runs caught: a primary key lost on a `char(36)` id (tool fixed), and a foreign key whose
  target table came later in the file (the tool now orders tables by their foreign keys).
* Removals done (2026-10-14): the system-users and `users_member` migrations are deleted (**26 files**), and so is
  `laravel_site_user_deprecations` with its tooling. Final check: **247 tables before, 243 after, 4 differences**,
  exactly the four removed tables (`laravel_systemsusers_users`, `laravel_systemsusers_sso_integrations`,
  `users_member`, `laravel_site_user_deprecations`). All 45 changed PHP files pass `php -l` on the server.
