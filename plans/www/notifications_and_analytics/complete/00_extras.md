# 00 — Extras (off-plan adjustments)

## What this file is

The numbered `complete/` files (`02` … `09`, `11`) are what the notifications_and_analytics plan **became**. This file is everything we had to add or fix that **was not a plan
step** — found on the way and fixed because leaving it would have broken the work in front of us.

**Repo:** `www`. Everything is uncommitted. Paths are from `w/www`.

**How to add to it.** Append a new `E*` section (next number, dated): symptom, cause, what changed, the files, and the operational consequence.

---

## Index

| # | Date | What | Why it is not in the plan |
|---|---|---|---|
| E1 | 2026-10-19 | Subscribers: five category filters named `id` ambiguously and failed on MySQL/MariaDB | Found by the characterization test written for step 11 (Subscribers); not a notification change |

---

## E1 — Subscribers: ambiguous `id` in five category filters (2026-10-19)

**Symptom.** On MySQL/MariaDB, publishing an article, saving an article, listing a category's subscribers and the mailing job each failed with `Column 'id' in where clause is ambiguous`.

**Cause.** Each filtered `->whereHas('categories', fn ($q) => $q->where('id', …))`. The relation is a many-to-many through `laravel_subscriber_category_subscriber`, so the sub-query joins two tables that both
have an `id`. SQLite (which the earlier tests used) lets it through; MySQL does not.

**What changed.** The filter names its table: `laravel_subscriber_categories.id`.

**Files.** `app/Http/Controllers/Externals/Subscribers/SubscribersController.php` (four filters: the subscriber list, the article publish and save paths) and `app/Jobs/SendSubscriberEmails.php` (one).

**Operational consequence.** None to run. A site on the old code could not publish an article to a category at all on MySQL; the fix ships with the next release. Covered by
`tests/Feature/Subscribers/SubscribersCharacterizationTest` and `SubscribersAdoptionTest`, which run against the real database.
