# 11 — Integrations and Notifications — Partial

## Status

- `ProcessFormSubmission` job: admin notification + configured mail handlers (server-side)
- `FormEvent` timeline entries for mail/notification outcomes
- Client-side mail/notification/analytics removed from `renderer.ts`
- `NotificationService` deep-links via `submission_reference_num`

## Not yet built

- Webhooks (§11.5)
- afs-connect submit ingest event
- Template token substitution (§11.3)
- Site Users link on submissions
