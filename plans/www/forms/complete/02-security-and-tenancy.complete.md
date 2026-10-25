# 02 — Security and Tenancy (P0) — Complete

## Status

Done. Admin routes authenticated and site-scoped; public surface throttled; submission IDOR
closed; server-side post-submit effects; file pipeline on Laravel storage; XSS escaping in admin
lists.

## Deploy steps

```bash
cd www
php artisan migrate
php artisan forms:backfill-sites --dry-run
php artisan forms:backfill-sites
php artisan test --filter=Forms
```

## Follow-up

- Mail-handler module still serves credentials unauthenticated (adjacent finding in plan §1.7.1)
- Origin enforcement on submit remains log-only until P6
