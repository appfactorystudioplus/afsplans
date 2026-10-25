# 04 — Database Schema (P1) — Complete

## Status

Done in `www`. Single migration `2026_04_11_000001_create_laravel_forms_tables.php` creates
the full schema (merged from the former expand migration). `FormJson` / `FormEnvelope` replace
entity encoding on forms code paths.

## Commands

| Command | Purpose |
|---------|---------|
| `forms:backfill-sites` | Stamp `website_reference_num` on legacy rows |
| `forms:migrate-encoding` | Entity → JSON encoding |
| `forms:seed-initial-versions` | v1 snapshot per form |
| `forms:link-submissions-to-versions` | Attach submissions to v1 |
| `forms:rebuild-answers` | Populate `laravel_form_answers` |
| `forms:sweep-orphan-files` | Purge unclaimed uploads |

## Deploy order

```bash
php artisan migrate
php artisan forms:backfill-sites
php artisan forms:migrate-encoding
php artisan forms:seed-initial-versions
php artisan forms:link-submissions-to-versions
php artisan forms:rebuild-answers
```
