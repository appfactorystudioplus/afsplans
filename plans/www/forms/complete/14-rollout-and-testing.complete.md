# 14 — Rollout and Testing — Partial

## Status

**P0 security gate (static):** Route middleware tests, external SDK static check (no client-side mail/notifications).

**Unit:** `FormEnvelope`, `FormSubmissionValidator`, `FormAllocationService`.

**Fresh install path:** Single migration, no legacy backfill commands required.

## Not yet built

- Two-tenant scoping integration test (§14.2 gate 2–3)
- Public projection field-by-field assertion (§14.2 gate 6)
- Full property-verification pass against bundled SurveyJS (§14.6)
- CI standing checks from §14.5

## Verification

```bash
cd www
php artisan test --filter=Forms
```
