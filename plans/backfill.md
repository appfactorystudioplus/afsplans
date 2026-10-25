**Backfill** = retroactively filling in data that's missing from rows that already exist, because the column or table that should hold it was added *after* those rows were created.

The name is an analogy to construction — you dig a trench, lay the pipe, then *backfill* the hole with earth. In data terms: you add a new column/table (the pipe), then go back over all the old records and populate it (the earth).

The defining feature is **direction in time**: a backfill only touches history. New rows get the value the normal way going forward; the backfill is the one-time catch-up for everything written before the feature existed.

## How this repo uses it

Two shapes, both here:

**1. A method inside a migration** — e.g. `backfillFromSystemsUsers()` in `2026_08_31_180000_create_site_users_schema.php`. The migration creates the new `laravel_site_user_*` tables, then in the same run copies every existing `laravel_systemsusers_users` row into them (mapping `systemsusers_id` → `site_users_id`, minting an identity row per user). Without that step the new tables would be empty and the old data stranded.

**2. Standalone artisan commands** named `*Backfill*` — `BuilderBackfillModuleRefs`, `MoneyBackfillCharges`, `FormsBackfillSites`, `ProcessorBackfillSubscriptions`, etc. These run once, on demand, usually because the data can't be derived at migration time (it needs an API call, or a decision, or it's slow enough to want progress output and a `--dry-run`). The plan's §9.5 "backfilling old rows by matching email" is this kind — it's *deliberately not* automatic because email matching is risky.

## Backfill vs. related terms

- **Migration** changes the *schema* (structure). A backfill fills the *data* into that new structure. A migration can contain a backfill, but "no ALTER-only migrations" (`AGENTS.md §5`) means schema and its backfill often ship together.
- **Seeder** creates *fresh* fixture/bootstrap data (demo store, admin users). A backfill *derives* values for *real, pre-existing* rows.
- **Sync** is ongoing/repeatable reconciliation with an external system. A backfill is a one-shot.

## Why it keeps coming up in the audit context

Adoption in this estate is almost always "add a nullable column, populate new activity, backfill old rows later" (plan §9.5). The nullable column ships safely on its own; the backfill is the separately-scheduled, separately-risky step — which is exactly why I flagged in the I9 analysis that the Site Users backfill *runs at migration time against empty tables during a clean run*, so it silently does nothing and `laravel_site_users` ends up empty.