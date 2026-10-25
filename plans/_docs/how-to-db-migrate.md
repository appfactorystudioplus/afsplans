Good question — and it's a different operation from what these files do. Your `.sql` files are *full* exports: schema + data. For merging into a populated database you want **data only**, plus a conflict rule.

## The three pieces

**1. Export data only.** In phpMyAdmin: Export → Custom → *Dump structure and data* → **"data only"**. That drops every `CREATE TABLE`, so there's no "already exists" collision — the file is just `INSERT` statements.

**2. Pick what happens on a key conflict.** phpMyAdmin's Export → Custom → *Function to use when dumping data* offers exactly this:

| statement | on conflict | use when |
|---|---|---|
| `INSERT` | errors and stops | you know there are no overlaps |
| `INSERT IGNORE` | skips the incoming row, **existing data wins** | topping up missing rows |
| `REPLACE` | deletes the existing row, **incoming data wins** | the export is authoritative |
| `INSERT … ON DUPLICATE KEY UPDATE` | updates named columns | true upsert, most precise |

There's also a separate "Use `INSERT IGNORE`" checkbox on the same screen.

**3. Watch `REPLACE` with foreign keys.** It's a `DELETE` + `INSERT`, not an update — so it fires `ON DELETE CASCADE`. Replacing one `laravel_processors` row could silently delete every child row hanging off it. `INSERT … ON DUPLICATE KEY UPDATE` doesn't have that problem.

## The gotcha that actually bites

**Auto-increment IDs.** If both databases independently assigned `id = 1` to *different* records, none of the above merges them — `INSERT IGNORE` throws your incoming row away, `REPLACE` destroys the existing one. Both look like they worked.

If you need to keep both rows, you have to omit the `id` column from the INSERT and let MySQL assign a new one. But then anything referencing the old id points at the wrong record, so you also have to remap the children. That is not a text transformation on a `.sql` file — it needs a staging pass.

## When it's a real merge, stage it

```bash
mysql -u root -e "CREATE DATABASE staging_import"
mysql -u root staging_import < your_export.sql
```

Then merge with SQL, where you can see and control every decision:

```sql
INSERT INTO live.orders (customer_id, total, created_at)
SELECT s.customer_id, s.total, s.created_at
FROM staging_import.orders s
LEFT JOIN live.orders l ON l.reference_num = s.reference_num
WHERE l.id IS NULL;
```

Joining on a **natural key** (`reference_num`, email, a UUID) rather than the auto-increment `id` is what makes this correct — your schema uses `reference_num` extensively, which is exactly the right column for this.

## For your files specifically

They're full exports, so today they only import into empty tables. If you want a merge-capable second set, the useful pair is: re-export **data only** with **`INSERT IGNORE`**, keeping these full exports as the from-scratch restore path. Two files per database, two different jobs.

I can also generate the data-only variants directly from the files you already have — strip every `CREATE TABLE`/`ALTER TABLE` section and rewrite `INSERT INTO` as `INSERT IGNORE INTO`, leaving your originals untouched. Say the word.




php artisan forms:backfill-sites      # if legacy rows exist
php artisan forms:migrate-encoding
php artisan forms:seed-initial-versions
php artisan forms:link-submissions-to-versions
php artisan forms:rebuild-answers
# then publish each form



